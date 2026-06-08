<?php

namespace App\Services\Pipeline;

use App\Models\PipelineEventRecord;

class PipelineEventRecorder
{
    private const RECORDED_EVENT_TYPES = [
        PipelineEvent::SCRAPE_REQUESTED,
        PipelineEvent::SCRAPE_MONITOR_REQUESTED,
        PipelineEvent::PAGE_SCRAPED,
        PipelineEvent::FILE_DISCOVERED,
        PipelineEvent::FILE_CONVERTED,
        PipelineEvent::CONTENT_INGESTED,
        PipelineEvent::JOB_FAILED,
    ];

    public function record(string $eventType, array $payload, ?string $source = null, ?string $message = null): ?PipelineEventRecord
    {
        if (!in_array($eventType, self::RECORDED_EVENT_TYPES, true)) {
            return null;
        }

        $event = PipelineEvent::normalize($eventType, $payload);
        if (!$this->hasIdentity($event)) {
            return null;
        }

        return PipelineEventRecord::query()->create([
            'task_id' => (string) $event['task_id'],
            'job_id' => (string) $event['job_id'],
            'event_type' => (string) $event['event_type'],
            'source' => $source ?: (string) ($event['source'] ?? 'hawki-rag-laravel'),
            'message' => $message ?: $this->messageFor($event),
            'payload' => $event,
            'created_at' => now(),
        ]);
    }

    public function timeline(string $taskId, array $filters = []): array
    {
        $limit = max(1, min(250, (int) ($filters['limit'] ?? 100)));
        $eventType = $this->nullableString($filters['event_type'] ?? $filters['eventType'] ?? null);
        $jobId = $this->nullableString($filters['job_id'] ?? $filters['jobId'] ?? null);

        $query = PipelineEventRecord::query()
            ->where('task_id', $taskId)
            ->when($eventType, fn ($query) => $query->where('event_type', $eventType))
            ->when($jobId, fn ($query) => $query->where('job_id', $jobId))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit);

        return $query->get()
            ->map(fn (PipelineEventRecord $event): array => $this->payload($event))
            ->all();
    }

    public function eventTypes(string $taskId): array
    {
        return PipelineEventRecord::query()
            ->where('task_id', $taskId)
            ->distinct()
            ->orderBy('event_type')
            ->pluck('event_type')
            ->values()
            ->all();
    }

    public function jobIds(string $taskId): array
    {
        return PipelineEventRecord::query()
            ->where('task_id', $taskId)
            ->distinct()
            ->orderBy('job_id')
            ->pluck('job_id')
            ->values()
            ->all();
    }

    public function payload(PipelineEventRecord $record): array
    {
        $payload = is_array($record->payload) ? $record->payload : [];
        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        return [
            'id' => $record->id,
            'taskId' => $record->task_id,
            'jobId' => $record->job_id,
            'eventType' => $record->event_type,
            'source' => $record->source,
            'message' => $record->message,
            'payload' => $payload,
            'status' => $payload['status'] ?? null,
            'jobType' => $payload['job_type'] ?? null,
            'sourceUrl' => $payload['source_url'] ?? null,
            'localPath' => $payload['local_path'] ?? null,
            'errorMessage' => $metadata['error_message']
                ?? $metadata['last_error_message']
                ?? $payload['error_message']
                ?? null,
            'createdAt' => $record->created_at?->format(DATE_ATOM),
            'at' => $record->created_at?->format(DATE_ATOM),
        ];
    }

    private function messageFor(array $event): string
    {
        $target = $this->target($event);
        $metadata = is_array($event['metadata'] ?? null) ? $event['metadata'] : [];
        $error = $metadata['error_message'] ?? $metadata['last_error_message'] ?? null;

        return match ((string) $event['event_type']) {
            PipelineEvent::SCRAPE_REQUESTED => $target ? "URL queued: {$target}" : 'URL queued',
            PipelineEvent::SCRAPE_MONITOR_REQUESTED => $target ? "Scrape monitor requested: {$target}" : 'Scrape monitor requested',
            PipelineEvent::PAGE_SCRAPED => $target ? "Page scraped: {$target}" : 'Page scraped',
            PipelineEvent::FILE_DISCOVERED => $target ? $this->fileLabel($target) . " discovered: {$target}" : 'File discovered',
            PipelineEvent::FILE_CONVERTED => $target ? "File converted: {$target}" : 'File converted',
            PipelineEvent::CONTENT_INGESTED => $target ? "Content ingested: {$target}" : 'Content ingested',
            PipelineEvent::JOB_FAILED => $error ? "Job failed: {$error}" : 'Job failed',
            default => (string) $event['event_type'],
        };
    }

    private function fileLabel(string $path): string
    {
        $extension = strtoupper(pathinfo($path, PATHINFO_EXTENSION));

        return $extension !== '' ? $extension : 'File';
    }

    private function target(array $event): ?string
    {
        $value = $event['local_path'] ?: $event['source_url'] ?: null;

        return $this->nullableString($value);
    }

    private function hasIdentity(array $event): bool
    {
        return $this->nullableString($event['task_id'] ?? null) !== null
            && $this->nullableString($event['job_id'] ?? null) !== null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
