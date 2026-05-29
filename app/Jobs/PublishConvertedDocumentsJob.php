<?php

namespace App\Jobs;

use App\Services\Pipeline\PipelineLogger;
use App\Services\Pipeline\PipelineStateService;
use App\Support\PipelineExitCode;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

class PublishConvertedDocumentsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 600;
    public array $backoff = [30, 90, 180];

    public function __construct(
        public readonly string $jobId,
        public readonly string $datasetPath,
    ) {
        $this->onQueue('default');
    }

    public function handle(PipelineStateService $pipelineState): void
    {
        $claim = $pipelineState->claimStage(
            $this->jobId,
            PipelineStateService::STAGE_INGEST,
            [
                'dataset_path' => $this->datasetPath,
                'metadata' => [
                    'source' => 'PublishConvertedDocumentsJob',
                    'attempt' => $this->attempts(),
                ],
            ],
            [PipelineStateService::STAGE_CONVERT],
        );

        if (!$claim) {
            return;
        }

        PipelineLogger::started('pipeline', [
            'job_id' => $this->jobId,
            'pipeline_stage' => 'ingest_trigger',
            'output_dir' => $this->datasetPath,
        ]);

        $exitCode = Artisan::call('rag:publish-converted-folder', [
            'folder' => $this->datasetPath,
        ]);

        $ingestStatus = $pipelineState->stageStatusValue($this->jobId, PipelineStateService::STAGE_INGEST);
        if (in_array($ingestStatus, ['received', 'processing', 'completed'], true)) {
            PipelineLogger::success('pipeline', [
                'job_id' => $this->jobId,
                'pipeline_stage' => 'ingest_events_published',
                'output_dir' => $this->datasetPath,
                'exit_code' => $exitCode,
            ]);
            return;
        }

        if ($exitCode === PipelineExitCode::PARTIAL_SUCCESS) {
            $pipelineState->skipStage($this->jobId, PipelineStateService::STAGE_INGEST, [
                'dataset_path' => $this->datasetPath,
                'metadata' => [
                    'reason' => 'No converted Markdown documents were published.',
                    'source' => 'PublishConvertedDocumentsJob',
                    'exitCode' => $exitCode,
                ],
            ]);
            return;
        }

        if ($exitCode !== PipelineExitCode::SUCCESS) {
            throw new RuntimeException("Publish converted documents command failed with exit code {$exitCode}.");
        }
    }

    public function failed(Throwable $exception): void
    {
        app(PipelineStateService::class)->failStage($this->jobId, PipelineStateService::STAGE_INGEST, [
            'dataset_path' => $this->datasetPath,
            'errors' => [[
                'message' => $exception->getMessage(),
                'type' => class_basename($exception),
                'updatedAt' => now()->toIso8601String(),
            ]],
            'metadata' => ['source' => 'PublishConvertedDocumentsJob'],
        ]);
    }
}
