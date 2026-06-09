<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\PipelineJob;
use App\Services\Pipeline\Events\PipelineEventRecorder;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class PipelineTaskTimelineService
{
    public function __construct(
        private PipelineEventRecorder $events,
        private PipelineTaskInputNormalizer $input,
        private PipelineTaskPayloadService $payloads,
        private PipelineJobRepository $jobRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function recentEvents(string $taskId, int $limit = 50, array $filters = []): array
    {
        $limit = max(1, min(250, $limit));

        $timeline = $this->events->timeline($taskId, array_merge($filters, ['limit' => $limit]));
        if ($timeline !== []) {
            return $timeline;
        }

        return $this->jobRepository
            ->forTaskByRecentUpdate($taskId)
            ->flatMap(fn (PipelineJob $job) => $this->payloads->eventsForJob($job))
            ->when($this->input->nullableString($filters['event_type'] ?? $filters['eventType'] ?? null), function (Collection $events, string $eventType): Collection {
                return $events->filter(fn (array $event): bool => ($event['eventType'] ?? null) === $eventType);
            })
            ->when($this->input->nullableString($filters['job_id'] ?? $filters['jobId'] ?? null), function (Collection $events, string $jobId): Collection {
                return $events->filter(fn (array $event): bool => ($event['jobId'] ?? null) === $jobId);
            })
            ->sortBy(fn (array $event): string => (string) ($event['at'] ?? ''))
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array{eventTypes:list<string>,jobIds:list<string>}
     */
    public function eventFilters(string $taskId): array
    {
        return [
            'eventTypes' => $this->events->eventTypes($taskId),
            'jobIds' => $this->events->jobIds($taskId),
        ];
    }
}
