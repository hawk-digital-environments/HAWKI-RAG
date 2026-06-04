<?php

namespace Tests\Feature;

use App\Models\JobProcessingState;
use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Models\ScrapeProcess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PipelineProofCaptureTest extends TestCase
{
    use RefreshDatabase;

    public function test_pipeline_proof_command_writes_expected_artifacts(): void
    {
        $jobId = 'proof-job-1';
        $datasetPath = storage_path('framework/testing/pipeline-proof-dataset');
        $outputPath = storage_path('framework/testing/pipeline-proof-output');

        File::deleteDirectory($datasetPath);
        File::deleteDirectory($outputPath);
        File::ensureDirectoryExists($datasetPath . '/converted_sample');
        File::put($datasetPath . '/sample.pdf', 'fake pdf');
        File::put($datasetPath . '/converted_sample/sample.md', '# Converted sample');
        File::put($datasetPath . '/converted_sample/conversion_meta.json', json_encode([
            'pipeline_job_id' => $jobId,
            'converted_id' => 'converted-doc-1',
            'source_file' => $datasetPath . '/sample.pdf',
            'output_dir' => $datasetPath . '/converted_sample',
            'files' => ['sample.md'],
            'converted_at' => now()->toIso8601String(),
        ]));

        Http::fake([
            '*' => Http::response([
                'status' => 'completed',
                'output_directory' => $datasetPath,
                'pages_crawled' => 1,
                'total_pages' => 1,
            ], 200),
        ]);

        $job = PipelineJob::query()->create([
            'job_id' => $jobId,
            'status' => PipelineJob::STATUS_COMPLETED,
            'current_stage' => 'ingest',
            'dataset_path' => $datasetPath,
            'source_url' => 'https://example.edu',
            'total_documents' => 1,
            'processed_documents' => 1,
            'failed_documents' => 0,
            'skipped_documents' => 0,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);

        foreach ([
            ['stage' => 'scrape', 'counts' => ['totalPages' => 1, 'pagesCrawled' => 1, 'failedUrls' => 0]],
            ['stage' => 'convert', 'counts' => ['total' => 1, 'sourceFiles' => 1, 'processed' => 1, 'convertedFiles' => 1, 'failed' => 0, 'failedFiles' => 0]],
            ['stage' => 'ingest', 'counts' => ['total' => 1, 'received' => 0, 'processing' => 0, 'completed' => 1, 'failed' => 0]],
        ] as $stage) {
            PipelineStageState::query()->create([
                'pipeline_job_id' => $job->id,
                'job_id' => $jobId,
                'stage' => $stage['stage'],
                'status' => PipelineJob::STATUS_COMPLETED,
                'counts' => $stage['counts'],
                'metadata' => $stage['stage'] === 'ingest'
                    ? ['publisher' => 'pipeline:ingestion-event-worker', 'folder' => $datasetPath]
                    : [],
                'started_at' => now()->subMinute(),
                'completed_at' => now(),
                'last_transition_at' => now(),
            ]);
        }

        ScrapeProcess::query()->create([
            'url' => 'https://example.edu',
            'label' => 'Example',
            'job_id' => $jobId,
            'stage' => 'completed',
            'request' => [
                'url' => 'https://example.edu',
                'output_dir' => '/app/shared/example',
            ],
        ]);

        JobProcessingState::query()->create([
            'job_id' => 'converted-doc-1',
            'stage' => JobProcessingState::STAGE_RAG_INGESTION,
            'source' => 'hawki-rag-laravel',
            'input_path' => $datasetPath . '/sample.pdf',
            'output_path' => $datasetPath . '/converted_sample/sample.md',
            'status' => JobProcessingState::STATUS_COMPLETED,
            'retry_count' => 0,
            'max_retries' => 3,
            'first_received_at' => now()->subSeconds(50),
            'processing_started_at' => now()->subSeconds(40),
            'completed_at' => now()->subSeconds(10),
            'trace_id' => 'converted-doc-1',
        ]);

        File::ensureDirectoryExists(storage_path('logs'));
        File::put(storage_path('logs/comm_logs.json'), json_encode([
            'message' => 'pipeline.stage',
            'context' => [
                'event' => 'pipeline.stage',
                'stage' => 'convert',
                'status' => 'success',
                'job_id' => $jobId,
                'pipeline_stage' => 'summary',
            ],
            'datetime' => now()->toIso8601String(),
        ]) . PHP_EOL);

        $this->artisan('pipeline:capture-proof', [
            'job_id' => $jobId,
            '--output' => $outputPath,
        ])->assertExitCode(0);

        $this->assertFileExists($outputPath . '/proof.md');
        $this->assertFileExists($outputPath . '/proof.json');
        $this->assertFileExists($outputPath . '/status-snapshots.jsonl');
        $this->assertFileExists($outputPath . '/pipeline-stage-logs.jsonl');
        $this->assertFileExists($outputPath . '/database-state.json');

        $proof = json_decode((string) file_get_contents($outputPath . '/proof.json'), true);
        $this->assertTrue($proof['finalProof']['allCompleted']);
        $this->assertSame('completed', $proof['finalProof']['scrapeStatus']);
        $this->assertSame('completed', $proof['finalProof']['convertStatus']);
        $this->assertSame('completed', $proof['finalProof']['ingestStatus']);
        $this->assertSame(1, $proof['convert']['convertedMetadataCount']);
        $this->assertSame(1, $proof['rabbitmqWorker']['completedRows']);
    }
}
