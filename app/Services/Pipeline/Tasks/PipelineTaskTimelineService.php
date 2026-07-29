<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\PipelineJob;
use App\Services\Pipeline\Repositories\Queries\PipelineTaskJobsQuery;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class PipelineTaskTimelineService
{
    public function __construct(
        private PipelineTaskInputNormalizer $input,
        private PipelineTaskPayloadService $payloads,
        private PipelineTaskJobsQuery $taskJobs,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function recentEvents(string $taskId, int $limit = 50, array $filters = []): array
    {
        $limit = max(1, min(250, $limit));

        return $this->jobEvents($taskId)
            ->when($this->input->nullableString($filters['event_type'] ?? $filters['eventType'] ?? null), function (Collection $events, string $eventType): Collection {
                return $events->filter(fn (array $event): bool => ($event['event_type'] ?? null) === $eventType);
            })
            ->when($this->input->nullableString($filters['job_id'] ?? $filters['jobId'] ?? null), function (Collection $events, string $jobId): Collection {
                return $events->filter(fn (array $event): bool => ($event['job_id'] ?? null) === $jobId);
            })
            ->sortBy(fn (array $event): string => (string) ($event['at'] ?? ''))
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array{event_types:list<string>,job_ids:list<string>}
     */
    public function eventFilters(string $taskId): array
    {
        $events = $this->jobEvents($taskId);

        return [
            'event_types' => $events
                ->pluck('event_type')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'job_ids' => $events
                ->pluck('job_id')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function jobEvents(string $taskId): Collection
    {
        return $this->taskJobs
            ->forTaskByRecentUpdate($taskId)
            ->flatMap(fn (PipelineJob $job) => $this->payloads->eventsForJob($job));
    }
}
