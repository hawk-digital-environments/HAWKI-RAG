<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineStageState;
use App\Services\Pipeline\Exceptions\PipelineWorkerEventException;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineScheduledRunRepository;
use App\Services\Pipeline\Repositories\PipelineStageStateRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use App\Services\Pipeline\Repositories\PipelineWorkerEventRepository;
use App\Services\Pipeline\Repositories\Queries\PipelineWorkerEventJobQuery;
use App\Services\Pipeline\State\PipelineStateService;
use App\Services\Pipeline\Tasks\PipelineTaskStatusRefresher;
use App\Services\Pipeline\Values\PipelineStage;
use App\Services\Pipeline\Values\PipelineStageStatus;
use App\Services\Pipeline\Values\PipelineWorker;
use App\Services\Pipeline\Values\PipelineWorkerEvent;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineWorkerEventService
{
    public function __construct(
        private PipelineWorkerEventRepository $events,
        private PipelineWorkerEventJobQuery $jobs,
        private PipelineTaskRepository $tasks,
        private IngestionSourceRepository $sources,
        private PipelineScheduledRunRepository $scheduledRuns,
        private PipelineStageStateRepository $stageStates,
        private PipelineStateService $pipelineState,
        private PipelineTaskStatusRefresher $taskStatuses,
        private PipelineTransactionRepository $transactions,
        private ClockInterface $clock = new Clock,
    ) {}

    /**
     * @return array{event_id:string,accepted:true,duplicate:bool,ignored:bool}
     */
    public function record(PipelineWorkerEvent $event): array
    {
        return $this->transactions->run(fn (): array => $this->recordWithinTransaction($event));
    }

    /**
     * @return array{event_id:string,accepted:true,duplicate:bool,ignored:bool}
     */
    private function recordWithinTransaction(PipelineWorkerEvent $event): array
    {
        $existingEvent = $this->events->findByEventId($event->eventId);
        if ($existingEvent !== null) {
            $this->ensureSamePayload($existingEvent->payload_hash, $event->payloadHash);

            if ($existingEvent->processed_at !== null) {
                return $this->receipt($event, duplicate: true, ignored: false);
            }
        }

        $job = $this->jobs->lockByJobId($event->jobId);
        if (! $job instanceof PipelineJob) {
            throw PipelineWorkerEventException::targetUnavailable();
        }

        $record = $this->events->claim($event, $job);
        $this->ensureSamePayload($record->payload_hash, $event->payloadHash);
        if ($record->processed_at !== null) {
            return $this->receipt($event, duplicate: true, ignored: false);
        }

        $source = $this->sources->lockBySourceId($event->sourceId);
        if (! $source instanceof IngestionSource) {
            throw PipelineWorkerEventException::targetUnavailable();
        }

        $taskId = $this->resolveTaskId($event, $job, $source);
        $task = $this->tasks->findByTaskId($taskId);
        if ($task === null) {
            throw PipelineWorkerEventException::targetUnavailable();
        }

        $this->ensureMatchingTarget($event, $job, $source, $taskId, (string) $task->dataset_id);

        $existingStage = $this->stageStates->findForJobStage($event->jobId, $event->stage->value);
        $processedAt = $this->now();
        $laterStage = $this->furthestLaterStageForRun($event);
        if ($this->isStaleTransition($event, $existingStage, $laterStage)) {
            $this->events->markProcessed($record, $processedAt);

            return $this->receipt($event, duplicate: false, ignored: true);
        }

        $this->applyStageTransition($event, $processedAt, $laterStage);
        $this->applySourceTransition($event, $source, $processedAt, $laterStage);
        $this->taskStatuses->recalculate($taskId);
        $this->events->markProcessed($record, $processedAt);

        return $this->receipt($event, duplicate: false, ignored: false);
    }

    private function ensureSamePayload(string $storedHash, string $incomingHash): void
    {
        if (! hash_equals($storedHash, $incomingHash)) {
            throw PipelineWorkerEventException::eventIdCollision();
        }
    }

    private function ensureMatchingTarget(
        PipelineWorkerEvent $event,
        PipelineJob $job,
        IngestionSource $source,
        string $taskId,
        string $taskDatasetId,
    ): void {
        $identifiersMatch = $job->job_type === PipelineJob::TYPE_INGEST
            && hash_equals((string) $job->task_id, $taskId)
            && hash_equals((string) $job->source_id, $event->sourceId)
            && hash_equals((string) $source->task_id, $taskId)
            && hash_equals((string) $source->dataset_id, $taskDatasetId)
            && hash_equals((string) $job->temporal_workflow_id, $event->workflowId)
            && hash_equals((string) $source->temporal_workflow_id, $event->workflowId)
            && $event->producer->stage() === $event->stage
            && $event->producer->acceptsActivity($event->activityId);

        if (! $identifiersMatch) {
            throw PipelineWorkerEventException::targetMismatch();
        }

        if (hash_equals((string) $job->temporal_run_id, $event->runId)) {
            return;
        }

        if ($this->canAdoptScheduledRun($event, $job, $source)) {
            $this->scheduledRuns->adopt($job, $source, $event, $this->now());

            return;
        }

        throw PipelineWorkerEventException::targetMismatch();
    }

    private function canAdoptScheduledRun(
        PipelineWorkerEvent $event,
        PipelineJob $job,
        IngestionSource $source,
    ): bool {
        $jobScheduleId = trim((string) $job->temporal_schedule_id);
        $sourceScheduleId = trim((string) $source->temporal_schedule_id);

        return $jobScheduleId !== ''
            && $sourceScheduleId !== ''
            && hash_equals($jobScheduleId, $sourceScheduleId)
            && in_array((string) $job->status, PipelineJob::TERMINAL_STATUSES, true)
            && $event->producer === PipelineWorker::Scraper
            && $event->stage === PipelineStage::Scrape
            && $event->status === PipelineStageStatus::Running;
    }

    private function resolveTaskId(
        PipelineWorkerEvent $event,
        PipelineJob $job,
        IngestionSource $source,
    ): string {
        $jobTaskId = (string) $job->task_id;
        $sourceTaskId = (string) $source->task_id;
        if ($jobTaskId === '' || $sourceTaskId === '' || ! hash_equals($jobTaskId, $sourceTaskId)) {
            throw PipelineWorkerEventException::targetMismatch();
        }

        if ($event->taskId !== null && ! hash_equals($jobTaskId, $event->taskId)) {
            throw PipelineWorkerEventException::targetMismatch();
        }

        return $jobTaskId;
    }

    private function isStaleTransition(
        PipelineWorkerEvent $event,
        ?PipelineStageState $state,
        ?PipelineStageState $laterStage,
    ): bool {
        if ($laterStage !== null && $event->status !== PipelineStageStatus::Completed) {
            return true;
        }

        if ($state === null) {
            return false;
        }

        $metadata = is_array($state->metadata) ? $state->metadata : [];
        $lastEvent = is_array($metadata['worker_event'] ?? null) ? $metadata['worker_event'] : [];
        $lastRunId = is_string($lastEvent['run_id'] ?? null) ? $lastEvent['run_id'] : null;
        if ($lastRunId === null || ! hash_equals($lastRunId, $event->runId)) {
            return false;
        }

        $currentStatus = PipelineStageStatus::tryFrom((string) $state->status);
        $lastActivityId = is_string($lastEvent['activity_id'] ?? null)
            ? $lastEvent['activity_id']
            : null;
        if ($lastActivityId !== null && ! hash_equals($lastActivityId, $event->activityId)) {
            return $currentStatus?->isTerminal() === true;
        }

        $lastAttempt = is_int($lastEvent['attempt'] ?? null)
            ? $lastEvent['attempt']
            : null;
        if ($lastAttempt !== null && $event->attempt !== $lastAttempt) {
            return $event->attempt < $lastAttempt;
        }

        $lastOccurredAt = $this->dateTimeOrNull($lastEvent['occurred_at'] ?? null);
        if ($lastOccurredAt !== null && $event->occurredAtInstant < $lastOccurredAt) {
            return true;
        }

        return $currentStatus?->isTerminal() === true;
    }

    private function applyStageTransition(
        PipelineWorkerEvent $event,
        Carbon $processedAt,
        ?PipelineStageState $laterStage,
    ): void {
        $attributes = $this->stageAttributes($event, $processedAt);
        if ($laterStage !== null) {
            $attributes['current_stage'] = $laterStage->stage;
            unset($attributes['index_status'], $attributes['error_message'], $attributes['finished_at']);
        }

        $state = match ($event->status) {
            PipelineStageStatus::Running => $this->pipelineState->startStage(
                $event->jobId,
                $event->stage->value,
                array_merge($attributes, ['completed_at' => null, 'failed_at' => null]),
            ),
            PipelineStageStatus::Completed => $this->pipelineState->completeStage(
                $event->jobId,
                $event->stage->value,
                array_merge($attributes, ['failed_at' => null]),
            ),
            PipelineStageStatus::Failed => $this->pipelineState->failStage(
                $event->jobId,
                $event->stage->value,
                $attributes,
            ),
            PipelineStageStatus::Skipped => $this->pipelineState->skipStage(
                $event->jobId,
                $event->stage->value,
                array_merge($attributes, ['failed_at' => null]),
            ),
        };

        if ($state === null) {
            throw PipelineWorkerEventException::stateUnavailable();
        }

        $nextStage = $event->status === PipelineStageStatus::Completed
            ? $this->nextStage($event->stage)
            : null;
        if ($nextStage === null) {
            return;
        }

        if ($this->stageStates->findForJobStage($event->jobId, $nextStage->value) !== null) {
            return;
        }

        $pending = $this->pipelineState->updateStage($event->jobId, $nextStage->value, [
            'status' => 'pending',
            'current_stage' => $nextStage->value,
            'index_status' => IngestionSource::STATUS_RUNNING,
            'error_message' => null,
            'finished_at' => null,
            'metadata' => [
                'awaiting_worker' => [
                    'producer' => $this->workerForStage($nextStage)->value,
                    'workflow_id' => $event->workflowId,
                    'run_id' => $event->runId,
                ],
            ],
        ]);

        if ($pending === null) {
            throw PipelineWorkerEventException::stateUnavailable();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function stageAttributes(PipelineWorkerEvent $event, Carbon $processedAt): array
    {
        $finalSuccess = $event->stage === PipelineStage::Ingest
            && $event->status === PipelineStageStatus::Completed;
        $failed = in_array($event->status, [PipelineStageStatus::Failed, PipelineStageStatus::Skipped], true);

        $attributes = [
            'counts' => $event->counts,
            'errors' => $event->errors,
            'warnings' => $event->warnings,
            'retry_count' => max(0, $event->attempt - 1),
            'metadata' => [
                'worker_event' => [
                    'event_id' => $event->eventId,
                    'event_type' => $event->eventType,
                    'producer' => $event->producer->value,
                    'workflow_id' => $event->workflowId,
                    'run_id' => $event->runId,
                    'activity_id' => $event->activityId,
                    'phase' => $event->phase,
                    'attempt' => $event->attempt,
                    'status' => $event->status->value,
                    'occurred_at' => $event->occurredAt,
                ],
                'metrics' => $event->metrics,
                'artifacts' => $event->artifacts,
                'manifest' => $event->manifest,
                'error_details' => $event->errorDetails,
            ],
            'current_stage' => $event->stage->value,
            'index_status' => $finalSuccess
                ? IngestionSource::STATUS_READY
                : ($failed ? IngestionSource::STATUS_FAILED : IngestionSource::STATUS_RUNNING),
            'error_message' => $failed ? $this->failureMessage($event) : null,
            'finished_at' => ($finalSuccess || $failed) ? $processedAt : null,
        ];

        if ($event->status === PipelineStageStatus::Skipped) {
            $attributes['job_status'] = PipelineJob::STATUS_SKIPPED;
        }

        return $attributes;
    }

    private function applySourceTransition(
        PipelineWorkerEvent $event,
        IngestionSource $source,
        Carbon $processedAt,
        ?PipelineStageState $laterStage,
    ): void {
        if ($laterStage !== null) {
            return;
        }

        $summary = [
            'event_id' => $event->eventId,
            'producer' => $event->producer->value,
            'stage' => $event->stage->value,
            'phase' => $event->phase,
            'status' => $event->status->value,
            'occurred_at' => $event->occurredAt,
            'workflow_id' => $event->workflowId,
            'run_id' => $event->runId,
            'attempt' => $event->attempt,
        ];

        if (in_array($event->status, [PipelineStageStatus::Failed, PipelineStageStatus::Skipped], true)) {
            $this->sources->markWorkerFailed($source, $this->failureMessage($event), $summary);

            return;
        }

        if ($event->stage === PipelineStage::Ingest && $event->status === PipelineStageStatus::Completed) {
            $this->sources->markWorkerReady($source, $processedAt, $event->documentVersion, $summary);

            return;
        }

        $this->sources->markWorkerRunning($source, $summary);
    }

    private function failureMessage(PipelineWorkerEvent $event): string
    {
        return $event->errorMessage()
            ?? sprintf('The %s worker reported the %s stage as %s.', $event->producer->value, $event->stage->value, $event->status->value);
    }

    private function nextStage(PipelineStage $stage): ?PipelineStage
    {
        return match ($stage) {
            PipelineStage::Scrape => PipelineStage::Convert,
            PipelineStage::Convert => PipelineStage::Ingest,
            PipelineStage::Ingest => null,
        };
    }

    private function workerForStage(PipelineStage $stage): PipelineWorker
    {
        return match ($stage) {
            PipelineStage::Scrape => PipelineWorker::Scraper,
            PipelineStage::Convert => PipelineWorker::Converter,
            PipelineStage::Ingest => PipelineWorker::Indexer,
        };
    }

    private function furthestLaterStageForRun(PipelineWorkerEvent $event): ?PipelineStageState
    {
        $laterStages = match ($event->stage) {
            PipelineStage::Scrape => [PipelineStage::Ingest, PipelineStage::Convert],
            PipelineStage::Convert => [PipelineStage::Ingest],
            PipelineStage::Ingest => [],
        };

        foreach ($laterStages as $stage) {
            $state = $this->stageStates->findForJobStage($event->jobId, $stage->value);
            if ($state !== null && $this->stateRunId($state) === $event->runId) {
                return $state;
            }
        }

        return null;
    }

    private function stateRunId(PipelineStageState $state): ?string
    {
        $metadata = is_array($state->metadata) ? $state->metadata : [];
        $workerEvent = is_array($metadata['worker_event'] ?? null) ? $metadata['worker_event'] : [];
        $awaitingWorker = is_array($metadata['awaiting_worker'] ?? null) ? $metadata['awaiting_worker'] : [];
        $runId = $workerEvent['run_id'] ?? $awaitingWorker['run_id'] ?? null;

        return is_string($runId) ? $runId : null;
    }

    private function dateTimeOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array{event_id:string,accepted:true,duplicate:bool,ignored:bool}
     */
    private function receipt(PipelineWorkerEvent $event, bool $duplicate, bool $ignored): array
    {
        return [
            'event_id' => $event->eventId,
            'accepted' => true,
            'duplicate' => $duplicate,
            'ignored' => $ignored,
        ];
    }

    private function now(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
