<?php

/*
|--------------------------------------------------------------------------
| Legacy pipeline path
|--------------------------------------------------------------------------
|
| Probably obsolete for the new Prefect + event-driven pipeline flow.
| This file belongs to the old synchronous handoff:
| scrape completion -> Laravel queue job -> convert command ->
| publisher job -> old ingestion queue.
|
| Keep only as a manual/backward-compatible fallback until the new
| task/event-worker pipeline is fully proven in production.
|
*/

namespace App\Jobs;

use App\Models\PipelineJob;
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

        $taskJob = $this->taskJob(PipelineJob::TYPE_INGEST, PipelineJob::STATUS_RUNNING);

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
            $this->finishTaskJob($taskJob, PipelineJob::STATUS_COMPLETED, [
                'exitCode' => $exitCode,
                'ingestStatus' => $ingestStatus,
            ]);

            PipelineLogger::success('pipeline', [
                'job_id' => $this->jobId,
                'pipeline_stage' => 'ingest_events_published',
                'output_dir' => $this->datasetPath,
                'exit_code' => $exitCode,
            ]);
            return;
        }

        if ($exitCode === PipelineExitCode::PARTIAL_SUCCESS) {
            $this->finishTaskJob($taskJob, PipelineJob::STATUS_SKIPPED, [
                'reason' => 'No converted Markdown documents were published.',
                'exitCode' => $exitCode,
            ]);

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
        $this->finishTaskJob($this->taskJob(PipelineJob::TYPE_INGEST, PipelineJob::STATUS_FAILED), PipelineJob::STATUS_FAILED, [
            'error' => $exception->getMessage(),
            'type' => class_basename($exception),
        ]);

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

    private function taskJob(string $type, string $status): ?PipelineJob
    {
        $parent = PipelineJob::query()->where('job_id', $this->jobId)->first();
        if (!$parent?->task_id) {
            return null;
        }

        return PipelineJob::query()->updateOrCreate(
            ['job_id' => 'publish_' . substr(hash('sha256', $this->jobId . '|' . $this->datasetPath), 0, 24)],
            [
                'task_id' => $parent->task_id,
                'parent_job_id' => $this->jobId,
                'job_type' => $type,
                'local_path' => $this->datasetPath,
                'status' => $status,
                'started_at' => now(),
                'metadata' => [
                    'source' => self::class,
                    'pipeline_job_id' => $this->jobId,
                ],
            ],
        );
    }

    private function finishTaskJob(?PipelineJob $job, string $status, array $metadata = []): void
    {
        if (!$job) {
            return;
        }

        $terminal = in_array($status, [
            PipelineJob::STATUS_COMPLETED,
            PipelineJob::STATUS_FAILED,
            PipelineJob::STATUS_SKIPPED,
            PipelineJob::STATUS_PARTIAL,
        ], true);

        $job->forceFill([
            'status' => $status,
            'completed_at' => $terminal ? now() : null,
            'finished_at' => $terminal ? now() : null,
            'metadata' => array_merge($job->metadata ?? [], $metadata),
        ])->save();
    }
}
