<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\IngestionSource;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;

#[Singleton]
readonly class IngestionSourceRepository
{
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
        return IngestionSource::query()->updateOrCreate(
            ['source_id' => $sourceId],
            array_merge($attributes, [
                'index_status' => IngestionSource::STATUS_RUNNING,
            ]),
        )->refresh();
    }

    public function markWorkflowStarted(
        IngestionSource $source,
        string $workflowId,
        ?string $runId,
        ?string $scheduleId,
    ): IngestionSource {
        $metadata = $source->metadata ?? [];
        $metadata['temporal'] = array_filter([
            'workflow_id' => $workflowId,
            'run_id' => $runId,
            'schedule_id' => $scheduleId,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $source->forceFill([
            'temporal_workflow_id' => $workflowId,
            'temporal_schedule_id' => $scheduleId ?: $source->temporal_schedule_id,
            'index_status' => IngestionSource::STATUS_RUNNING,
            'metadata' => $metadata,
        ])->save();

        return $source->refresh();
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
        $metadata = $source->metadata ?? [];
        $metadata['error'] = $message;

        $source->forceFill([
            'index_status' => IngestionSource::STATUS_FAILED,
            'metadata' => $metadata,
        ])->save();

        return $source->refresh();
    }

    public function markCancelled(IngestionSource $source): IngestionSource
    {
        $source->forceFill([
            'index_status' => IngestionSource::STATUS_CANCELLED,
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
