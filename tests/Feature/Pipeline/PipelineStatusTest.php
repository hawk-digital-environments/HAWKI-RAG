<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class PipelineStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsApiUser();
        Http::fake([
            '*' => Http::response(['detail' => 'Crawler job not found.'], 404),
        ]);
    }

    public function test_it_returns_the_persisted_ingest_stage_without_placeholder_data(): void
    {
        $job = $this->createJob('job-with-ingest-projection');

        PipelineStageState::query()->create([
            'pipeline_job_id' => $job->id,
            'job_id' => $job->job_id,
            'stage' => 'ingest',
            'status' => 'running',
            'counts' => ['total' => 5, 'completed' => 2],
            'metadata' => ['temporal_activity' => 'ingest_markdown_files'],
            'errors' => ['document-3 is waiting for retry'],
            'warnings' => ['embedding provider is slow'],
            'retry_count' => 2,
            'max_retries' => 5,
            'started_at' => now()->subMinute(),
        ]);

        $response = $this->getJson("/api/pipeline/status/{$job->job_id}");

        $response
            ->assertOk()
            ->assertJsonPath('stages.ingest.status', 'running')
            ->assertJsonPath('stages.ingest.counts.total', 5)
            ->assertJsonPath('stages.ingest.counts.completed', 2)
            ->assertJsonPath('stages.ingest.errors.0', 'document-3 is waiting for retry')
            ->assertJsonPath('stages.ingest.warnings.0', 'embedding provider is slow')
            ->assertJsonPath('stages.ingest.retry.retry_count', 2)
            ->assertJsonPath('stages.ingest.retry.max_retries', 5)
            ->assertJsonPath('stages.ingest.metadata.temporal_activity', 'ingest_markdown_files')
            ->assertJsonPath('tracked.found', true);

        $this->assertNull($response->json('stages.ingest.message'));
    }

    public function test_it_marks_a_missing_ingest_projection_as_not_tracked(): void
    {
        $job = $this->createJob('job-without-ingest-projection');

        $this->getJson("/api/pipeline/status/{$job->job_id}")
            ->assertOk()
            ->assertJsonPath('stages.ingest.status', 'not_tracked')
            ->assertJsonPath('stages.ingest.message', 'No persisted ingest stage has been recorded for this job.')
            ->assertJsonPath('tracked.found', true);
    }

    public function test_it_marks_an_unknown_job_as_not_tracked(): void
    {
        $this->getJson('/api/pipeline/status/job-without-any-projection')
            ->assertOk()
            ->assertJsonPath('stages.ingest.status', 'not_tracked')
            ->assertJsonPath('tracked.found', false);
    }

    public function test_it_uses_an_ingest_projection_created_during_conversion_reconciliation(): void
    {
        $datasetPath = storage_path('framework/testing/pipeline-status-empty-dataset');
        File::deleteDirectory($datasetPath);
        File::ensureDirectoryExists($datasetPath);

        try {
            $job = $this->createJob('job-with-reconciled-ingest-projection', $datasetPath);

            $this->getJson("/api/pipeline/status/{$job->job_id}")
                ->assertOk()
                ->assertJsonPath('stages.convert.status', PipelineJob::STATUS_SKIPPED)
                ->assertJsonPath('stages.ingest.status', PipelineJob::STATUS_SKIPPED)
                ->assertJsonPath(
                    'stages.ingest.metadata.reason',
                    'Conversion skipped because no supported source files were found.',
                );
        } finally {
            File::deleteDirectory($datasetPath);
        }
    }

    private function createJob(string $jobId, ?string $datasetPath = null): PipelineJob
    {
        return PipelineJob::query()->create([
            'job_id' => $jobId,
            'job_type' => PipelineJob::TYPE_INGEST,
            'status' => PipelineJob::STATUS_RUNNING,
            'current_stage' => 'ingest',
            'dataset_path' => $datasetPath,
            'metadata' => [],
            'started_at' => now()->subMinute(),
        ]);
    }
}
