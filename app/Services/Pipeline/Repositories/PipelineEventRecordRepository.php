<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\PipelineEventRecord;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class PipelineEventRecordRepository
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): PipelineEventRecord
    {
        return PipelineEventRecord::query()->create($attributes);
    }

    public function existsForJobEvent(string $taskId, string $jobId, string $eventType): bool
    {
        return PipelineEventRecord::query()
            ->where('task_id', $taskId)
            ->where('job_id', $jobId)
            ->where('event_type', $eventType)
            ->exists();
    }

    /**
     * @return Collection<int, PipelineEventRecord>
     */
    public function timeline(string $taskId, ?string $eventType, ?string $jobId, int $limit): Collection
    {
        return PipelineEventRecord::query()
            ->where('task_id', $taskId)
            ->when($eventType, fn ($query) => $query->where('event_type', $eventType))
            ->when($jobId, fn ($query) => $query->where('job_id', $jobId))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return list<string>
     */
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

    /**
     * @return list<string>
     */
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
}
