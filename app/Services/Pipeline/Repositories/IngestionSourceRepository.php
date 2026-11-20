<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\Dataset;
use App\Models\IngestionSource;
use App\Models\PipelineJob;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

#[Singleton]
readonly class IngestionSourceRepository
{
    public function __construct(private DatabaseManager $database) {}

    public function findBySourceId(string $sourceId): ?IngestionSource
    {
        return IngestionSource::query()
            ->where('source_id', $sourceId)
            ->first();
    }

    public function lockBySourceId(string $sourceId): ?IngestionSource
    {
        return IngestionSource::query()
            ->where('source_id', $sourceId)
            ->lockForUpdate()
            ->first();
    }

    public function ownedManagedSources(string $documentId, string $datasetId): Collection
    {
        return IngestionSource::query()->where('dataset_id', $datasetId)->where(function ($query) use ($documentId): void {
            $query->where('metadata->request->metadata->managed_document_id', $documentId)
                ->orWhere('metadata->request->managed_document_id', $documentId);
        })->orderBy('source_id')->get();
    }

    public function deleteIfOwnedByTask(string $sourceId, string $taskId): bool
    {
        return (bool) IngestionSource::query()
            ->where('source_id', $sourceId)
            ->where('task_id', $taskId)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upsertStarting(string $sourceId, array $attributes): IngestionSource
    {
        return $this->database->transaction(function () use ($sourceId, $attributes): IngestionSource {
            if (! $this->findBySourceId($sourceId) && isset($attributes['dataset_id'])) {
                // New reservations serialize with dataset archival. Existing source
                // recovery retains its established job/source lock order.
                $dataset = Dataset::query()->where('dataset_id', $attributes['dataset_id'])->lockForUpdate()->first();
                if (! $dataset || $dataset->status !== Dataset::STATUS_ACTIVE) {
                    throw new \RuntimeException('Dataset is unavailable for new ingestion.');
                }
            }
            $current = $this->lockBySourceId($sourceId);
            if ($current && isset($current->metadata['managed_deletion'])) {
                throw new \RuntimeException('Managed deletion prevents restarting this ingestion source.');
            }
            if ($current) {
                if (isset($current->metadata['managed_dispatch']) && in_array($current->index_status, [IngestionSource::STATUS_RUNNING, IngestionSource::STATUS_PENDING], true)) {
                    throw new \RuntimeException('Managed source already has a reserved or active ingestion execution.');
                }
                foreach ($current->metadata['managed_writers'] ?? [] as $writer) {
                    if ($writer['status'] !== 'released') {
                        throw new \RuntimeException('Managed writer quiescence must be reconciled before recovery.');
                    }
                }
                // Recovery callers may hold an old model while callbacks advance the ledger.
                $metadata = $attributes['metadata'] ?? [];
                foreach ($current->metadata ?? [] as $key => $value) {
                    if (str_starts_with($key, 'managed_') || in_array($key, ['temporal', 'worker_event'], true)) {
                        $metadata[$key] = $value;
                    }
                }
                $attributes['metadata'] = $metadata;
            }

            return IngestionSource::query()->updateOrCreate(['source_id' => $sourceId], array_merge($attributes, [
                'index_status' => IngestionSource::STATUS_RUNNING,
            ]))->refresh();
        });
    }

    /** Reserve one recovery identity and commit it before any external start. */
    public function reserveManagedRecovery(IngestionSource $source, PipelineJob $job, string $workflowId, array $input, array $attributes = []): IngestionSource
    {
        return $this->database->transaction(function () use ($source, $job, $workflowId, $input, $attributes): IngestionSource {
            $lockedJob = PipelineJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            $current = $this->lockBySourceId($source->source_id);
            if (! $current || $current->temporal_workflow_id !== $source->temporal_workflow_id
                || isset($current->metadata['managed_deletion']) || ($current->metadata['managed_writer_gate'] ?? null) === 'closed') {
                throw new \RuntimeException('Managed recovery identity changed or was fenced; reconcile before retrying.');
            }
            foreach ($current->metadata['managed_writers'] ?? [] as $writer) {
                if ($writer['status'] !== 'released') {
                    throw new \RuntimeException('Another managed writer acquired permission before recovery reservation.');
                }
            }
            $historical = $current->metadata['managed_dispatch']['input']['ingestion'] ?? null;
            if (! is_array($historical)) {
                throw new \RuntimeException('Managed recovery requires immutable historical sink settings.');
            }
            $input['ingestion'] = $historical;
            $attributes['metadata'] ??= $current->metadata ?? [];
            $current = $this->upsertStarting($current->source_id, $attributes);
            $current = $this->recordPlannedWorkflow($current, $workflowId, $input);
            $lockedJob->forceFill(['temporal_workflow_id' => $workflowId, 'temporal_run_id' => null])->save();

            return $current;
        });
    }

    public function recordPlannedWorkflow(IngestionSource $source, string $workflowId, ?array $input = null): IngestionSource
    {
        $metadata = $source->metadata ?? [];
        if ($source->temporal_workflow_id) {
            $runId = $metadata['temporal']['run_id'] ?? $metadata['worker_event']['run_id'] ?? null;
            $metadata['managed_writer_history'][$source->temporal_workflow_id.'|'.($runId ?? 'unknown')] = ['workflow_id' => $source->temporal_workflow_id, 'run_id' => $runId];
        }
        if ($source->temporal_workflow_id !== $workflowId) {
            unset($metadata['temporal']['run_id'], $metadata['worker_event']);
        }
        if ($input !== null) {
            $metadata['managed_dispatch'] = ['input' => $input, 'attempted' => true];
        }
        $source->forceFill(['temporal_workflow_id' => $workflowId, 'metadata' => $metadata])->save();

        return $source->refresh();
    }

    public function markManagedDeletion(IngestionSource $source, string $operationId, string $status): IngestionSource
    {
        $metadata = $source->metadata ?? [];
        $metadata['managed_deletion'] = ['operation_id' => $operationId, 'status' => $status];
        $source->forceFill(['metadata' => $metadata, 'index_status' => $status])->save();

        return $source->refresh();
    }

    public function markWorkflowStarted(
        IngestionSource $source,
        string $workflowId,
        ?string $runId,
        ?string $scheduleId,
    ): IngestionSource {
        if (isset($source->metadata['managed_dispatch'])) {
            return $this->database->transaction(fn () => $this->confirmWorkflowStarted($source, $workflowId, $runId));
        }
        $metadata = $source->metadata ?? [];
        $metadata['temporal'] = array_filter([
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'schedule_id' => $scheduleId,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
        $metadata['managed_writer_history'][$workflowId.'|'.($runId ?? 'unknown')] = ['workflow_id' => $workflowId, 'run_id' => $runId];
        unset($metadata['managed_writer_history'][$workflowId.'|unknown']);

        $source->forceFill([
            'temporal_workflow_id' => $workflowId,
            'temporal_schedule_id' => $scheduleId ?: $source->temporal_schedule_id,
            'index_status' => IngestionSource::STATUS_RUNNING,
            'metadata' => $metadata,
        ])->save();

        return $source->refresh();
    }

    public function confirmWorkflowStarted(
        IngestionSource $source,
        string $workflowId,
        ?string $runId,
    ): IngestionSource {
        $current = IngestionSource::query()
            ->whereKey($source->getKey())
            ->lockForUpdate()
            ->firstOrFail();
        if (
            $current->temporal_workflow_id
            && ! hash_equals((string) $current->temporal_workflow_id, $workflowId)
        ) {
            throw new \RuntimeException('Temporal workflow confirmation does not match the ingestion source.');
        }

        $metadata = is_array($current->metadata) ? $current->metadata : [];
        $temporal = is_array($metadata['temporal'] ?? null)
            ? $metadata['temporal']
            : [];
        $workerEvent = is_array($metadata['worker_event'] ?? null)
            ? $metadata['worker_event']
            : [];
        if (isset($metadata['managed_dispatch']) && $runId && ! empty($temporal['run_id']) && $runId !== $temporal['run_id']) {
            throw new \RuntimeException('Temporal acknowledgement conflicts with the registered managed writer run.');
        }
        $metadata['temporal'] = array_filter([
            ...$temporal,
            'workflow_id' => $workflowId,
            'run_id' => $runId
                ?: ($temporal['run_id'] ?? $workerEvent['run_id'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
        $resolvedRunId = $metadata['temporal']['run_id'] ?? null;
        $metadata['managed_writer_history'][$workflowId.'|'.($resolvedRunId ?? 'unknown')] = ['workflow_id' => $workflowId, 'run_id' => $resolvedRunId];
        if ($resolvedRunId) {
            unset($metadata['managed_writer_history'][$workflowId.'|unknown']);
        }
        $attributes = [
            'temporal_workflow_id' => $workflowId,
            'metadata' => $metadata,
        ];
        if ($current->index_status !== IngestionSource::STATUS_READY && ! isset($metadata['managed_deletion'])) {
            $attributes['index_status'] = IngestionSource::STATUS_RUNNING;
            $attributes['ready_at'] = null;
        }
        $current->forceFill($attributes)->save();

        return $current->refresh();
    }

    public function markReady(IngestionSource $source, Carbon $readyAt): IngestionSource
    {
        $source->forceFill([
            'index_status' => IngestionSource::STATUS_READY,
            'ready_at' => $readyAt,
        ])->save();

        return $source->refresh();
    }

    public function markFailed(IngestionSource $source, string $message): IngestionSource
    {
        if (isset($source->metadata['managed_dispatch'])) {
            return $this->database->transaction(function () use ($source, $message) {
                $current = $this->lockBySourceId($source->source_id);
                if ($current->index_status === IngestionSource::STATUS_READY || isset($current->metadata['managed_deletion'])) {
                    return $current;
                }
                $metadata = $current->metadata ?? [];
                $metadata['error'] = $message;
                $current->forceFill(['metadata' => $metadata, 'index_status' => IngestionSource::STATUS_FAILED])->save();

                return $current->refresh();
            });
        }
        $metadata = $source->metadata ?? [];
        $metadata['error'] = $message;

        $source->forceFill([
            'index_status' => IngestionSource::STATUS_FAILED,
            'metadata' => $metadata,
        ])->save();

        return $source->refresh();
    }

    public function markCancelled(IngestionSource $source, bool $changeStatus = true): IngestionSource
    {
        return $this->database->transaction(function () use ($source, $changeStatus): IngestionSource {
            $current = $this->lockBySourceId($source->source_id);
            if (isset($current->metadata['managed_deletion'])) {
                return $current;
            }
            $metadata = $current->metadata ?? [];
            $request = $metadata['request'] ?? [];
            if (isset($metadata['managed_dispatch']) || isset(($request['metadata'] ?? $request)['managed_document_id'])) {
                $metadata['managed_writer_gate'] = 'closed';
            }
            $current->forceFill(['metadata' => $metadata, ...($changeStatus ? ['index_status' => IngestionSource::STATUS_CANCELLED] : [])])->save();

            return $current->refresh();
        });
    }

    public function markDeletionRequested(
        IngestionSource $source,
        string $idempotencyHash,
        Carbon $requestedAt,
        bool $workflowClosed,
    ): IngestionSource {
        $metadata = is_array($source->metadata) ? $source->metadata : [];
        $deletion = is_array($metadata['text_deletion'] ?? null)
            ? $metadata['text_deletion']
            : [];
        $metadata['text_deletion'] = [
            ...$deletion,
            'status' => IngestionSource::STATUS_DELETING,
            'idempotency_hash' => $idempotencyHash,
            'requested_at' => $requestedAt->toAtomString(),
            'workflow_closed' => $workflowClosed,
        ];

        $source->forceFill([
            'index_status' => IngestionSource::STATUS_DELETING,
            'metadata' => $metadata,
        ])->save();

        return $source->refresh();
    }

    public function markDeletionWorkflowClosed(IngestionSource $source): IngestionSource
    {
        $metadata = is_array($source->metadata) ? $source->metadata : [];
        $deletion = is_array($metadata['text_deletion'] ?? null)
            ? $metadata['text_deletion']
            : [];
        $metadata['text_deletion'] = [
            ...$deletion,
            'workflow_closed' => true,
        ];

        $source->forceFill(['metadata' => $metadata])->save();

        return $source->refresh();
    }

    public function markDeleted(IngestionSource $source, Carbon $deletedAt): IngestionSource
    {
        $metadata = is_array($source->metadata) ? $source->metadata : [];
        $deletion = is_array($metadata['text_deletion'] ?? null)
            ? $metadata['text_deletion']
            : [];
        $metadata['text_deletion'] = [
            ...$deletion,
            'status' => IngestionSource::STATUS_DELETED,
            'deleted_at' => $deletedAt->toAtomString(),
        ];

        $source->forceFill([
            'index_status' => IngestionSource::STATUS_DELETED,
            'markdown_storage_path' => null,
            'raw_storage_path' => null,
            'ready_at' => null,
            'metadata' => $metadata,
        ])->save();

        return $source->refresh();
    }

    /**
     * @param  array{event_id:string,producer:string,stage:string,phase:string,status:string,occurred_at:string,workflow_id:string,run_id:string,attempt:int}  $workerEvent
     */
    public function markWorkerRunning(IngestionSource $source, array $workerEvent): IngestionSource
    {
        $source->forceFill([
            'index_status' => IngestionSource::STATUS_RUNNING,
            'metadata' => $this->metadataWithWorkerEvent($source, $workerEvent, clearError: true),
        ])->save();

        return $source->refresh();
    }

    /**
     * @param  array{event_id:string,producer:string,stage:string,phase:string,status:string,occurred_at:string,workflow_id:string,run_id:string,attempt:int}  $workerEvent
     */
    public function markWorkerReady(
        IngestionSource $source,
        Carbon $readyAt,
        ?string $documentVersion,
        array $workerEvent,
    ): IngestionSource {
        $source->forceFill(array_filter([
            'index_status' => IngestionSource::STATUS_READY,
            'ready_at' => $readyAt,
            'last_scraped_at' => $source->last_scraped_at ?? $readyAt,
            'document_version' => $documentVersion,
            'metadata' => $this->metadataWithWorkerEvent($source, $workerEvent, clearError: true),
        ], static fn (mixed $value): bool => $value !== null))->save();

        return $source->refresh();
    }

    /**
     * @param  array{event_id:string,producer:string,stage:string,phase:string,status:string,occurred_at:string,workflow_id:string,run_id:string,attempt:int}  $workerEvent
     */
    public function markWorkerFailed(
        IngestionSource $source,
        string $message,
        array $workerEvent,
    ): IngestionSource {
        $metadata = $this->metadataWithWorkerEvent($source, $workerEvent);
        $metadata['error'] = $message;

        $source->forceFill([
            'index_status' => IngestionSource::STATUS_FAILED,
            'metadata' => $metadata,
        ])->save();

        return $source->refresh();
    }

    /**
     * @param  array{event_id:string,producer:string,stage:string,phase:string,status:string,occurred_at:string,workflow_id:string,run_id:string,attempt:int}  $workerEvent
     * @return array<string, mixed>
     */
    private function metadataWithWorkerEvent(
        IngestionSource $source,
        array $workerEvent,
        bool $clearError = false,
    ): array {
        $metadata = is_array($source->metadata) ? $source->metadata : [];
        $metadata['worker_event'] = $workerEvent;
        if ($clearError) {
            unset($metadata['error']);
        }

        return $metadata;
    }
}
