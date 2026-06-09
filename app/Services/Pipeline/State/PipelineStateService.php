<?php

declare(strict_types=1);

namespace App\Services\Pipeline\State;

use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use App\Services\Pipeline\Repositories\PipelineStageStateRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

#[Singleton]
readonly class PipelineStateService
{
    public const STAGE_SCRAPE = 'scrape';

    public const STAGE_CONVERT = 'convert';

    public const STAGE_INGEST = 'ingest';

    public function __construct(
        private readonly PipelineJobRepository $jobs,
        private readonly PipelineStageStateRepository $stageStates,
    ) {}

    public function ensureJob(string $jobId, array $attributes = []): ?PipelineJob
    {
        if (! $this->tablesAvailable()) {
            return null;
        }

        return $this->jobs->ensureStateJob(
            $jobId,
            $this->jobAttributes($attributes),
            Carbon::now(),
            PipelineJob::STATUS_PENDING,
        );
    }

    public function startStage(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->updateStage($jobId, $stage, array_merge($attributes, [
            'status' => $attributes['status'] ?? PipelineJob::STATUS_RUNNING,
            'started_at' => $attributes['started_at'] ?? Carbon::now(),
        ]));
    }

    public function completeStage(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->updateStage($jobId, $stage, array_merge($attributes, [
            'status' => PipelineJob::STATUS_COMPLETED,
            'completed_at' => $attributes['completed_at'] ?? Carbon::now(),
        ]));
    }

    public function skipStage(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->updateStage($jobId, $stage, array_merge($attributes, [
            'status' => PipelineJob::STATUS_SKIPPED,
            'completed_at' => $attributes['completed_at'] ?? Carbon::now(),
        ]));
    }

    public function partialStage(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->updateStage($jobId, $stage, array_merge($attributes, [
            'status' => PipelineJob::STATUS_PARTIAL,
            'completed_at' => $attributes['completed_at'] ?? Carbon::now(),
        ]));
    }

    public function failStage(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        return $this->updateStage($jobId, $stage, array_merge($attributes, [
            'status' => PipelineJob::STATUS_FAILED,
            'failed_at' => $attributes['failed_at'] ?? Carbon::now(),
            'completed_at' => $attributes['completed_at'] ?? Carbon::now(),
        ]));
    }

    public function updateStage(string $jobId, string $stage, array $attributes = []): ?PipelineStageState
    {
        if (! $this->tablesAvailable()) {
            return null;
        }

        $job = $this->ensureJob($jobId, $attributes);
        if (! $job) {
            return null;
        }

        $state = $this->stageStates->upsertForJob(
            $job,
            $jobId,
            $stage,
            $this->stageAttributes($attributes),
            $this->startedStatuses(),
            Carbon::now(),
        );

        $this->refreshJobFromStages($job, $stage, $attributes);

        return $state;
    }

    public function incrementStageCounts(string $jobId, string $stage, array $deltas, array $attributes = []): ?PipelineStageState
    {
        if (! $this->tablesAvailable()) {
            return null;
        }

        $existing = $this->stageStates->findForJobStage($jobId, $stage);

        $counts = is_array($existing?->counts) ? $existing->counts : [];
        foreach ($deltas as $key => $delta) {
            $counts[$key] = max(0, (int) ($counts[$key] ?? 0) + (int) $delta);
        }

        return $this->updateStage($jobId, $stage, array_merge($attributes, [
            'counts' => $counts,
        ]));
    }

    public function claimStage(
        string $jobId,
        string $stage,
        array $attributes = [],
        array $requiredCompletedStages = [],
        bool $force = false
    ): ?PipelineStageState {
        if (! $this->tablesAvailable()) {
            return null;
        }

        return DB::transaction(function () use ($jobId, $stage, $attributes, $requiredCompletedStages, $force): ?PipelineStageState {
            $job = $this->jobs->firstOrCreateClaimJob($jobId, $stage, Carbon::now());

            foreach ($requiredCompletedStages as $requiredStage) {
                $required = $this->stageStates->lockForJobStage($jobId, $requiredStage);

                if (! $required || $required->status !== PipelineJob::STATUS_COMPLETED) {
                    return null;
                }
            }

            $state = $this->stageStates->lockForJobStage($jobId, $stage);

            if ($state && ! $force && in_array($state->status, $this->nonClaimableStatuses(), true)) {
                return null;
            }

            $state = $this->stageStates->saveForJob(
                $state ?? $this->stageStates->newForJobStage($jobId, $stage),
                $job,
                $this->stageAttributes(array_merge($attributes, [
                    'status' => $attributes['status'] ?? PipelineJob::STATUS_RUNNING,
                    'started_at' => $attributes['started_at'] ?? Carbon::now(),
                ])),
                $this->startedStatuses(),
                Carbon::now(),
            );

            $this->refreshJobFromStages($job, $stage, $attributes);

            return $state;
        });
    }

    public function stageStatusValue(string $jobId, string $stage): ?string
    {
        if (! $this->tablesAvailable()) {
            return null;
        }

        return $this->stageStates->statusValue($jobId, $stage);
    }

    public function isStageCompleted(string $jobId, string $stage): bool
    {
        return $this->stageStatusValue($jobId, $stage) === PipelineJob::STATUS_COMPLETED;
    }

    public function isStageClaimedOrDone(string $jobId, string $stage): bool
    {
        $status = $this->stageStatusValue($jobId, $stage);

        return $status !== null && in_array($status, $this->nonClaimableStatuses(), true);
    }

    public function status(string $jobId): ?array
    {
        if (! $this->tablesAvailable()) {
            return null;
        }

        $job = $this->jobs->findWithOrderedStagesByJobId($jobId);

        if (! $job) {
            return null;
        }

        return [
            'jobId' => $job->job_id,
            'datasetPath' => $job->dataset_path,
            'currentStage' => $job->current_stage,
            'status' => $job->status,
            'documentCounts' => [
                'total' => $job->total_documents,
                'processed' => $job->processed_documents,
                'failed' => $job->failed_documents,
                'skipped' => $job->skipped_documents,
            ],
            'startedAt' => $this->dateValue($job->started_at),
            'completedAt' => $this->dateValue($job->completed_at),
            'metadata' => $job->metadata ?? [],
            'stages' => $job->stages
                ->mapWithKeys(fn (PipelineStageState $stage) => [
                    $stage->stage => $this->stageStatus($stage),
                ])
                ->all(),
        ];
    }

    private function refreshJobFromStages(PipelineJob $job, string $currentStage, array $attributes): void
    {
        $stages = $this->stageStates->forPipelineJob($job);

        $statuses = $stages->pluck('status')->all();
        $counts = $this->rollupCounts($stages);
        $status = $this->overallStatus($statuses);

        $this->jobs->updateStageRollup(
            $job,
            $currentStage,
            $status,
            $counts,
            $status === PipelineJob::STATUS_RUNNING ? null : $this->latestCompletedAt($stages),
            $attributes,
        );
    }

    private function overallStatus(array $statuses): string
    {
        if ($statuses === []) {
            return PipelineJob::STATUS_PENDING;
        }
        if (array_intersect($statuses, [PipelineJob::STATUS_RUNNING, 'processing', 'received'])) {
            return PipelineJob::STATUS_RUNNING;
        }
        if (in_array(PipelineJob::STATUS_FAILED, $statuses, true)) {
            return in_array(PipelineJob::STATUS_COMPLETED, $statuses, true) ? PipelineJob::STATUS_PARTIAL : PipelineJob::STATUS_FAILED;
        }
        if (in_array(PipelineJob::STATUS_PARTIAL, $statuses, true)) {
            return PipelineJob::STATUS_PARTIAL;
        }
        if (count(array_unique($statuses)) === 1 && $statuses[0] === PipelineJob::STATUS_SKIPPED) {
            return PipelineJob::STATUS_SKIPPED;
        }
        if (count($statuses) < 3) {
            return PipelineJob::STATUS_PARTIAL;
        }

        return PipelineJob::STATUS_COMPLETED;
    }

    /**
     * @param  iterable<PipelineStageState>  $stages
     * @return array{total:int,processed:int,failed:int,skipped:int}
     */
    private function rollupCounts(iterable $stages): array
    {
        $counts = ['total' => 0, 'processed' => 0, 'failed' => 0, 'skipped' => 0];
        foreach ($stages as $stage) {
            $stageCounts = is_array($stage->counts) ? $stage->counts : [];
            $counts['total'] += (int) ($stageCounts['total'] ?? $stageCounts['sourceFiles'] ?? $stageCounts['totalPages'] ?? 0);
            $counts['processed'] += (int) ($stageCounts['processed'] ?? $stageCounts['convertedFiles'] ?? $stageCounts['completed'] ?? $stageCounts['pagesCrawled'] ?? 0);
            $counts['failed'] += (int) ($stageCounts['failed'] ?? $stageCounts['failedFiles'] ?? $stageCounts['failedUrls'] ?? 0);
            $counts['skipped'] += (int) ($stageCounts['skipped'] ?? $stageCounts['skippedFiles'] ?? 0);
        }

        return $counts;
    }

    private function latestCompletedAt(iterable $stages): ?Carbon
    {
        $latest = null;
        foreach ($stages as $stage) {
            if (! $stage->completed_at) {
                continue;
            }
            if (! $latest || $stage->completed_at->gt($latest)) {
                $latest = $stage->completed_at;
            }
        }

        return $latest;
    }

    private function nonClaimableStatuses(): array
    {
        return [
            PipelineJob::STATUS_RUNNING,
            PipelineJob::STATUS_COMPLETED,
            PipelineJob::STATUS_FAILED,
            PipelineJob::STATUS_SKIPPED,
            PipelineJob::STATUS_PARTIAL,
            'processing',
            'received',
            'cancel_requested',
        ];
    }

    private function startedStatuses(): array
    {
        return [
            PipelineJob::STATUS_RUNNING,
            'processing',
            'received',
        ];
    }

    private function jobAttributes(array $attributes): array
    {
        return array_filter([
            'status' => $attributes['job_status'] ?? null,
            'task_id' => $attributes['task_id'] ?? $attributes['taskId'] ?? null,
            'parent_job_id' => $attributes['parent_job_id'] ?? $attributes['parentJobId'] ?? null,
            'job_type' => $attributes['job_type'] ?? $attributes['jobType'] ?? null,
            'current_stage' => $attributes['current_stage'] ?? null,
            'dataset_path' => $attributes['dataset_path'] ?? null,
            'source_url' => $attributes['source_url'] ?? null,
            'local_path' => $attributes['local_path'] ?? $attributes['localPath'] ?? null,
            'content_hash' => $attributes['content_hash'] ?? $attributes['contentHash'] ?? null,
            'label' => $attributes['label'] ?? null,
            'metadata' => $attributes['job_metadata'] ?? null,
            'started_at' => $attributes['job_started_at'] ?? $attributes['started_at'] ?? null,
            'completed_at' => $attributes['job_completed_at'] ?? null,
            'finished_at' => $attributes['job_finished_at'] ?? $attributes['finished_at'] ?? null,
        ], static fn ($value) => $value !== null);
    }

    private function stageAttributes(array $attributes): array
    {
        return array_filter([
            'status' => $attributes['status'] ?? null,
            'counts' => $attributes['counts'] ?? null,
            'metadata' => $attributes['metadata'] ?? null,
            'errors' => $attributes['errors'] ?? null,
            'warnings' => $attributes['warnings'] ?? null,
            'retry_count' => $attributes['retry_count'] ?? null,
            'max_retries' => $attributes['max_retries'] ?? null,
            'started_at' => $attributes['started_at'] ?? null,
            'completed_at' => $attributes['completed_at'] ?? null,
            'failed_at' => $attributes['failed_at'] ?? null,
        ], static fn ($value) => $value !== null);
    }

    private function stageStatus(PipelineStageState $stage): array
    {
        return [
            'status' => $stage->status,
            'startedAt' => $this->dateValue($stage->started_at),
            'completedAt' => $this->dateValue($stage->completed_at),
            'failedAt' => $this->dateValue($stage->failed_at),
            'counts' => $stage->counts ?? [],
            'errors' => $stage->errors ?? [],
            'warnings' => $stage->warnings ?? [],
            'retry' => [
                'retryCount' => $stage->retry_count,
                'maxRetries' => $stage->max_retries,
            ],
            'metadata' => $stage->metadata ?? [],
            'updatedAt' => $this->dateValue($stage->updated_at),
        ];
    }

    private function tablesAvailable(): bool
    {
        return $this->stageStates->tablesAvailable();
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $value ? (string) $value : null;
    }
}
