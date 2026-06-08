<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Datasets\DatasetService;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use App\Services\Pipeline\Repositories\PipelineScrapeHistoryRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Singleton]
class PipelineTaskService
{
    public function __construct(
        private readonly PipelineEventRecorder $events,
        private readonly PipelineEventBus $eventBus,
        private readonly DatasetService $datasets,
        private readonly PipelineTaskCounterService $counters,
        private readonly PipelineTaskEventPayloadService $eventPayloads,
        private readonly PipelineTaskInputNormalizer $input,
        private readonly PipelineTaskMetadataService $metadata,
        private readonly PipelineTaskPayloadService $payloads,
        private readonly PipelineTaskSourceResolver $sources,
        private readonly PipelineTaskStatusService $statuses,
        private readonly PipelineTaskRepository $taskRepository,
        private readonly PipelineJobRepository $jobRepository,
        private readonly PipelineScrapeHistoryRepository $scrapeHistoryRepository,
    ) {
    }

    public function start(array $input): PipelineTask
    {
        $task = DB::transaction(function () use ($input): PipelineTask {
            $dataset = $this->datasets->ensure($input);
            $task = $this->taskRepository->createRunningTask(
                $this->input->taskId($input),
                $dataset,
                Carbon::now(),
                $this->counters->defaults(),
                [
                    'request' => $input,
                    'orchestration' => 'laravel',
                    'rabbitmq' => ['event_bus' => true],
                    'dataset' => $this->metadata->dataset($dataset),
                ],
            );

            foreach ($this->sources->resolve($input) as $url) {
                $this->createScrapeJob($task, $url);
            }

            return $this->recalculateTaskStatus($task);
        });

        return $task->refresh();
    }

    public function show(string $taskId): ?array
    {
        $task = $this->taskRepository->findWithOrderedJobs($taskId);
        if (!$task) {
            return null;
        }

        $task = $this->recalculateTaskStatus($task);

        return $this->payloads->detail($task, $this->activeJobCount($task), $this->counters->defaults());
    }

    public function list(int $limit = 30): array
    {
        return $this->taskRepository->recent($limit)
            ->map(function (PipelineTask $task): array {
                $task = $this->recalculateTaskStatus($task);

                return $this->payloads->summary($task, $this->activeJobCount($task), $this->counters->defaults());
            })
            ->all();
    }

    public function jobs(string $taskId): array
    {
        return $this->jobRepository->forTaskOrdered($taskId)
            ->map(fn (PipelineJob $job) => $this->payloads->job($job))
            ->all();
    }

    public function failedJobs(string $taskId): array
    {
        return $this->jobRepository->failedForTask($taskId)
            ->map(fn (PipelineJob $job) => $this->payloads->job($job))
            ->all();
    }

    public function recentEvents(string $taskId, int $limit = 50, array $filters = []): array
    {
        $limit = max(1, min(250, $limit));

        $timeline = $this->events->timeline($taskId, array_merge($filters, ['limit' => $limit]));
        if ($timeline !== []) {
            return $timeline;
        }

        $events = $this->jobRepository
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

        return $events;
    }

    public function eventFilters(string $taskId): array
    {
        return [
            'eventTypes' => $this->events->eventTypes($taskId),
            'jobIds' => $this->events->jobIds($taskId),
        ];
    }

    public function upsertJob(string $taskId, array $input): PipelineJob
    {
        $task = $this->taskRepository->findByTaskIdOrFail($taskId);
        $jobId = $this->input->jobId($input);
        $status = $this->input->jobStatus($input['status'] ?? null);
        $existing = $this->jobRepository->findByJobId($jobId);
        $metadata = array_merge(
            is_array($existing?->metadata) ? $existing->metadata : [],
            is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
        );

        $job = $this->jobRepository->upsertForTask(
            $jobId,
            $task,
            [
                'parent_job_id' => $this->input->nullableString($input['parent_job_id'] ?? $input['parentJobId'] ?? null) ?? $existing?->parent_job_id,
                'job_type' => $this->input->nullableString($input['job_type'] ?? $input['jobType'] ?? null) ?? $existing?->job_type,
                'source_url' => $this->input->nullableString($input['source_url'] ?? $input['sourceUrl'] ?? null) ?? $existing?->source_url,
                'local_path' => $this->input->nullableString($input['local_path'] ?? $input['localPath'] ?? null) ?? $existing?->local_path,
                'content_hash' => $this->input->nullableString($input['content_hash'] ?? $input['contentHash'] ?? null) ?? $existing?->content_hash,
                'status' => $status,
                'error_message' => $this->input->nullableString($input['error_message'] ?? $input['errorMessage'] ?? null),
                'started_at' => $this->input->date($input['started_at'] ?? $input['startedAt'] ?? null)
                    ?? $existing?->started_at
                    ?? (in_array($status, [PipelineJob::STATUS_RUNNING, PipelineJob::STATUS_QUEUED], true) ? Carbon::now() : null),
                'finished_at' => $this->input->isTerminalStatus($status) ? Carbon::now() : null,
                'metadata' => $metadata,
            ],
        );

        $this->recalculateTaskStatus($task);

        return $job->refresh();
    }

    public function retryFailedJobs(string $taskId): ?PipelineTask
    {
        $task = $this->taskRepository->findByTaskId($taskId);
        if (!$task) {
            return null;
        }

        $jobs = $this->jobRepository->failedForRetry($task);

        foreach ($jobs as $job) {
            $metadata = $job->metadata ?? [];
            $metadata['retry_count'] = (int) ($metadata['retry_count'] ?? 0) + 1;
            $metadata['retried_at'] = now()->toIso8601String();

            $job = $this->jobRepository->markQueuedForRetry($job, $metadata);
            $this->publishRetryEventForJob($task, $job);
        }

        if ($jobs->isNotEmpty()) {
            $task = $this->taskRepository->markFailedJobsRetried(
                $task,
                $this->metadata->appendEvent($task, 'failed_jobs_retried'),
            );
        }

        return $this->recalculateTaskStatus($task);
    }

    public function retry(string $taskId): ?PipelineTask
    {
        return $this->retryFailedJobs($taskId);
    }

    public function completeIfIdle(string $taskId): ?PipelineTask
    {
        $task = $this->taskRepository->findByTaskId($taskId);

        return $task ? $this->recalculateTaskStatus($task) : null;
    }

    public function recalculateTaskStatus(PipelineTask|string $task): PipelineTask
    {
        $task = $task instanceof PipelineTask
            ? $task
            : $this->taskRepository->findByTaskIdOrFail($task);

        $jobs = $this->jobRepository->forTask($task->task_id);
        $counters = $this->counters->forJobs($jobs);
        $status = $this->statuses->resolve($task, $counters, $jobs->isNotEmpty());

        return $this->taskRepository->updateStatusCounters($task, $status['status'], $status['finished_at'], $counters);
    }

    public function refreshCounters(PipelineTask $task): PipelineTask
    {
        return $this->recalculateTaskStatus($task);
    }

    private function createScrapeJob(PipelineTask $task, string $url): PipelineJob
    {
        $contentHash = hash('sha256', $url);
        $alreadyScraped = $this->scrapeHistoryRepository->hasCompletedScrape($url, $contentHash);
        $status = $alreadyScraped ? PipelineJob::STATUS_SKIPPED : PipelineJob::STATUS_QUEUED;
        $now = Carbon::now();

        $job = $this->jobRepository->createScrapeJob(
            ($alreadyScraped ? 'skipped_' : 'scrape_') . substr(hash('sha256', $task->task_id . '|' . $url), 0, 24),
            $task,
            $url,
            $contentHash,
            $status,
            $now,
            $alreadyScraped ? $now : null,
            array_merge($this->metadata->taskJob($task), [
                'reason' => $alreadyScraped ? 'URL was already scraped by Laravel.' : 'Queued for scraper worker through RabbitMQ.',
                'dataset_id' => $task->dataset_id,
            ]),
        );

        if (!$alreadyScraped) {
            $this->publishScrapeRequested($task, $job);
        }

        return $job;
    }

    private function publishRetryEventForJob(PipelineTask $task, PipelineJob $job): void
    {
        $eventType = $this->eventPayloads->retryEventType($job);
        if ($eventType === null) {
            return;
        }

        $this->publishEvent($eventType, $this->eventPayloads->forJob($task, $job, $eventType));
    }

    private function publishScrapeRequested(PipelineTask $task, PipelineJob $job): void
    {
        $this->publishEvent(
            PipelineEvent::SCRAPE_REQUESTED,
            $this->eventPayloads->forJob($task, $job, PipelineEvent::SCRAPE_REQUESTED),
        );
    }

    private function publishEvent(string $routingKey, array $payload): void
    {
        try {
            $this->eventBus->publish($routingKey, $payload);
        } catch (Throwable $exception) {
            Log::warning('Pipeline RabbitMQ event publish failed.', [
                'routing_key' => $routingKey,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function activeJobCount(PipelineTask $task): int
    {
        return $this->jobRepository->countForTaskWithStatuses($task->task_id, $this->statuses->activeJobStatuses());
    }
}
