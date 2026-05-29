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

class ConvertPipelineDatasetJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 7200;
    public array $backoff = [60, 180, 300];

    public function __construct(
        public readonly string $jobId,
        public readonly string $datasetPath,
    ) {
        $this->onQueue('default');
    }

    public function handle(PipelineStateService $pipelineState): void
    {
        if ($pipelineState->isStageCompleted($this->jobId, PipelineStateService::STAGE_INGEST)) {
            return;
        }

        $claim = $pipelineState->claimStage(
            $this->jobId,
            PipelineStateService::STAGE_CONVERT,
            [
                'dataset_path' => $this->datasetPath,
                'metadata' => [
                    'source' => 'ConvertPipelineDatasetJob',
                    'attempt' => $this->attempts(),
                ],
            ],
            [PipelineStateService::STAGE_SCRAPE],
        );

        if (!$claim) {
            if ($pipelineState->isStageCompleted($this->jobId, PipelineStateService::STAGE_CONVERT)
                && !$pipelineState->isStageClaimedOrDone($this->jobId, PipelineStateService::STAGE_INGEST)) {
                PublishConvertedDocumentsJob::dispatch($this->jobId, $this->datasetPath);
            }
            return;
        }

        PipelineLogger::started('pipeline', [
            'job_id' => $this->jobId,
            'pipeline_stage' => 'convert_trigger',
            'output_dir' => $this->datasetPath,
        ]);

        $exitCode = Artisan::call('convert:crawled-files', [
            'outputDir' => $this->datasetPath,
            '--job-id' => $this->jobId,
            '--scan-all' => true,
            '--existing' => 'continue',
        ]);

        $convertStatus = $pipelineState->stageStatusValue($this->jobId, PipelineStateService::STAGE_CONVERT);
        if ($exitCode === PipelineExitCode::SUCCESS) {
            $pipelineState->completeStage($this->jobId, PipelineStateService::STAGE_CONVERT, [
                'dataset_path' => $this->datasetPath,
                'metadata' => [
                    'source' => 'ConvertPipelineDatasetJob',
                    'exitCode' => $exitCode,
                ],
            ]);

            PipelineLogger::success('pipeline', [
                'job_id' => $this->jobId,
                'pipeline_stage' => 'convert_to_ingest_trigger',
                'output_dir' => $this->datasetPath,
            ]);

            PublishConvertedDocumentsJob::dispatch($this->jobId, $this->datasetPath);
            return;
        }

        if ($exitCode === PipelineExitCode::PARTIAL_SUCCESS && $convertStatus === 'skipped') {
            $pipelineState->skipStage($this->jobId, PipelineStateService::STAGE_INGEST, [
                'dataset_path' => $this->datasetPath,
                'metadata' => [
                    'reason' => 'Conversion skipped because no supported source files were found.',
                    'source' => 'ConvertPipelineDatasetJob',
                ],
            ]);
            return;
        }

        if ($exitCode === PipelineExitCode::PARTIAL_SUCCESS) {
            $pipelineState->partialStage($this->jobId, PipelineStateService::STAGE_CONVERT, [
                'dataset_path' => $this->datasetPath,
                'metadata' => [
                    'reason' => 'Conversion completed with partial success; ingest was not triggered automatically.',
                    'source' => 'ConvertPipelineDatasetJob',
                    'exitCode' => $exitCode,
                ],
            ]);
            return;
        }

        throw new RuntimeException("Conversion command failed with exit code {$exitCode}.");
    }

    public function failed(Throwable $exception): void
    {
        app(PipelineStateService::class)->failStage($this->jobId, PipelineStateService::STAGE_CONVERT, [
            'dataset_path' => $this->datasetPath,
            'errors' => [[
                'message' => $exception->getMessage(),
                'type' => class_basename($exception),
                'updatedAt' => now()->toIso8601String(),
            ]],
            'metadata' => ['source' => 'ConvertPipelineDatasetJob'],
        ]);
    }
}
