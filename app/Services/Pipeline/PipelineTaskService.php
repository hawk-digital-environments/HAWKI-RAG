<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\Dataset;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Datasets\DatasetService;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use App\Services\Pipeline\Repositories\PipelineScrapeHistoryRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PipelineTaskService
{
    private const ACTIVE_JOB_STATUSES = [
        PipelineJob::STATUS_QUEUED,
        PipelineJob::STATUS_RUNNING,
    ];

    public function __construct(
        private readonly PipelineEventRecorder $events,
        private readonly DatasetService $datasets,
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
                $this->taskId($input),
                $dataset,
                Carbon::now(),
                $this->defaultCounters(),
                [
                    'request' => $input,
                    'orchestration' => 'laravel',
                    'rabbitmq' => ['event_bus' => true],
                    'dataset' => $this->datasetMetadata($dataset),
                ],
            );

            foreach ($this->resolveUrls($input) as $url) {
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

        return [
            'taskId' => $task->task_id,
            'datasetId' => $task->dataset_id,
            'status' => $task->status,
            'startedAt' => $this->dateValue($task->started_at),
            'finishedAt' => $this->dateValue($task->finished_at),
            'counters' => $task->counters ?? $this->defaultCounters(),
            'metadata' => $task->metadata ?? [],
            'activeJobs' => $this->activeJobCount($task),
            'jobs' => $task->jobs
                ->map(fn (PipelineJob $job) => $this->jobPayload($job))
                ->all(),
            'updatedAt' => now()->toIso8601String(),
        ];
    }

    public function list(int $limit = 30): array
    {
        return $this->taskRepository->recent($limit)
            ->map(function (PipelineTask $task): array {
                $task = $this->recalculateTaskStatus($task);

                return [
                    'taskId' => $task->task_id,
                    'datasetId' => $task->dataset_id,
                    'status' => $task->status,
                    'startedAt' => $this->dateValue($task->started_at),
                    'finishedAt' => $this->dateValue($task->finished_at),
                    'counters' => $task->counters ?? $this->defaultCounters(),
                    'metadata' => $task->metadata ?? [],
                    'activeJobs' => $this->activeJobCount($task),
                    'updatedAt' => now()->toIso8601String(),
                ];
            })
            ->all();
    }

    public function jobs(string $taskId): array
    {
        return $this->jobRepository->forTaskOrdered($taskId)
            ->map(fn (PipelineJob $job) => $this->jobPayload($job))
            ->all();
    }

    public function failedJobs(string $taskId): array
    {
        return $this->jobRepository->failedForTask($taskId)
            ->map(fn (PipelineJob $job) => $this->jobPayload($job))
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
            ->flatMap(fn (PipelineJob $job) => $this->eventsForJob($job))
            ->when($this->nullableString($filters['event_type'] ?? $filters['eventType'] ?? null), function (Collection $events, string $eventType): Collection {
                return $events->filter(fn (array $event): bool => ($event['eventType'] ?? null) === $eventType);
            })
            ->when($this->nullableString($filters['job_id'] ?? $filters['jobId'] ?? null), function (Collection $events, string $jobId): Collection {
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
        $jobId = $this->nullableString($input['job_id'] ?? $input['jobId'] ?? null) ?? (string) Str::uuid();
        $status = $this->normalizeJobStatus($input['status'] ?? null);
        $existing = $this->jobRepository->findByJobId($jobId);
        $metadata = array_merge(
            is_array($existing?->metadata) ? $existing->metadata : [],
            is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
        );

        $job = $this->jobRepository->upsertForTask(
            $jobId,
            $task,
            [
                'parent_job_id' => $this->nullableString($input['parent_job_id'] ?? $input['parentJobId'] ?? null) ?? $existing?->parent_job_id,
                'job_type' => $this->nullableString($input['job_type'] ?? $input['jobType'] ?? null) ?? $existing?->job_type,
                'source_url' => $this->nullableString($input['source_url'] ?? $input['sourceUrl'] ?? null) ?? $existing?->source_url,
                'local_path' => $this->nullableString($input['local_path'] ?? $input['localPath'] ?? null) ?? $existing?->local_path,
                'content_hash' => $this->nullableString($input['content_hash'] ?? $input['contentHash'] ?? null) ?? $existing?->content_hash,
                'status' => $status,
                'error_message' => $this->nullableString($input['error_message'] ?? $input['errorMessage'] ?? null),
                'started_at' => $this->dateInput($input['started_at'] ?? $input['startedAt'] ?? null)
                    ?? $existing?->started_at
                    ?? (in_array($status, [PipelineJob::STATUS_RUNNING, PipelineJob::STATUS_QUEUED], true) ? Carbon::now() : null),
                'finished_at' => $this->terminalStatus($status) ? Carbon::now() : null,
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
                $this->appendMetadataEvent($task, 'failed_jobs_retried'),
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
        $counters = $this->countersFor($jobs);
        $status = $task->status;
        $finishedAt = $task->finished_at;

        if ($jobs->isEmpty()) {
            $status = $task->status === PipelineTask::STATUS_RUNNING
                ? PipelineTask::STATUS_RUNNING
                : PipelineTask::STATUS_PENDING;
            $finishedAt = null;
        } elseif ($counters['queued'] > 0 || $this->runningCount($jobs) > 0) {
            $status = PipelineTask::STATUS_RUNNING;
            $finishedAt = null;
        } elseif ($counters['failed'] > 0) {
            $status = PipelineTask::STATUS_FAILED;
            $finishedAt ??= Carbon::now();
        } else {
            $status = PipelineTask::STATUS_COMPLETED;
            $finishedAt ??= Carbon::now();
        }

        return $this->taskRepository->updateStatusCounters($task, $status, $finishedAt, $counters);
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
            array_merge($this->taskJobMetadata($task), [
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
        $metadata = $job->metadata ?? [];
        $eventType = match ($job->job_type) {
            PipelineJob::TYPE_SCRAPE => PipelineEvent::SCRAPE_REQUESTED,
            PipelineJob::TYPE_CONVERT => PipelineEvent::FILE_DISCOVERED,
            PipelineJob::TYPE_INGEST => in_array($metadata['source_event_type'] ?? null, [PipelineEvent::PAGE_SCRAPED, PipelineEvent::FILE_CONVERTED], true)
                ? (string) $metadata['source_event_type']
                : PipelineEvent::FILE_CONVERTED,
            default => null,
        };

        if ($eventType === null) {
            return;
        }

        $payload = $this->eventPayloadForJob($task, $job, $eventType);
        $this->publishEvent($eventType, $payload);
    }

    private function publishScrapeRequested(PipelineTask $task, PipelineJob $job): void
    {
        $this->publishEvent(
            PipelineEvent::SCRAPE_REQUESTED,
            $this->eventPayloadForJob($task, $job, PipelineEvent::SCRAPE_REQUESTED),
        );
    }

    private function eventPayloadForJob(PipelineTask $task, PipelineJob $job, string $eventType): array
    {
        $metadata = $job->metadata ?? [];
        $sourceJobId = $metadata['source_job_id'] ?? null;
        $jobId = $job->job_id;
        $jobType = $job->job_type;

        if ($job->job_type === PipelineJob::TYPE_INGEST && in_array($eventType, [PipelineEvent::PAGE_SCRAPED, PipelineEvent::FILE_CONVERTED], true)) {
            $jobId = is_string($sourceJobId) && $sourceJobId !== '' ? $sourceJobId : ($job->parent_job_id ?: $job->job_id);
            $jobType = PipelineEvent::jobTypeFor($eventType);
        }

        return [
            'task_id' => $task->task_id,
            'job_id' => $jobId,
            'parent_job_id' => $job->parent_job_id,
            'dataset_id' => $task->dataset_id,
            'job_type' => $jobType,
            'source_url' => $job->source_url,
            'local_path' => $job->local_path,
            'content_hash' => $job->content_hash,
            'status' => $job->status,
            'metadata' => $metadata,
        ];
    }

    private function taskJobMetadata(PipelineTask $task): array
    {
        $metadata = $task->metadata ?? [];
        $request = is_array($metadata['request'] ?? null) ? $metadata['request'] : [];
        $requestMetadata = is_array($request['metadata'] ?? null) ? $request['metadata'] : [];

        return array_merge($requestMetadata, [
            'dataset' => is_array($metadata['dataset'] ?? null) ? $metadata['dataset'] : [],
        ]);
    }

    private function datasetMetadata(Dataset $dataset): array
    {
        return [
            'dataset_id' => $dataset->dataset_id,
            'qdrant_collection' => $dataset->qdrant_collection,
            'neo4j_namespace' => $dataset->neo4j_namespace,
        ];
    }

    private function publishEvent(string $routingKey, array $payload): void
    {
        try {
            app(PipelineEventBus::class)->publish($routingKey, $payload);
        } catch (Throwable $exception) {
            Log::warning('Pipeline RabbitMQ event publish failed.', [
                'routing_key' => $routingKey,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function countersFor(Collection $jobs): array
    {
        $byStatus = $jobs->countBy('status');

        $counters = [
            'queued' => (int) ($byStatus[PipelineJob::STATUS_QUEUED] ?? 0),
            'scraped' => $jobs
                ->where('job_type', PipelineJob::TYPE_SCRAPE)
                ->where('status', PipelineJob::STATUS_COMPLETED)
                ->count(),
            'files_found' => $jobs
                ->where('job_type', PipelineJob::TYPE_CONVERT)
                ->count(),
            'converted' => $jobs
                ->where('job_type', PipelineJob::TYPE_CONVERT)
                ->where('status', PipelineJob::STATUS_COMPLETED)
                ->count(),
            'ingested' => $jobs
                ->where('job_type', PipelineJob::TYPE_INGEST)
                ->where('status', PipelineJob::STATUS_COMPLETED)
                ->count(),
            'skipped' => (int) ($byStatus[PipelineJob::STATUS_SKIPPED] ?? 0),
            'failed' => (int) ($byStatus[PipelineJob::STATUS_FAILED] ?? 0),
        ];

        return array_merge($counters, [
            'jobs_total' => $jobs->count(),
            'jobs_active' => $counters['queued'] + $this->runningCount($jobs),
            'jobs_queued' => $counters['queued'],
            'jobs_pending' => $counters['queued'],
            'jobs_running' => $this->runningCount($jobs),
            'jobs_completed' => (int) ($byStatus[PipelineJob::STATUS_COMPLETED] ?? 0),
            'jobs_failed' => $counters['failed'],
            'jobs_skipped' => $counters['skipped'],
            'scrape_jobs' => $jobs->where('job_type', PipelineJob::TYPE_SCRAPE)->count(),
            'convert_jobs' => $jobs->where('job_type', PipelineJob::TYPE_CONVERT)->count(),
            'ingest_jobs' => $jobs->where('job_type', PipelineJob::TYPE_INGEST)->count(),
        ]);
    }

    private function defaultCounters(): array
    {
        return [
            'queued' => 0,
            'scraped' => 0,
            'files_found' => 0,
            'converted' => 0,
            'ingested' => 0,
            'skipped' => 0,
            'failed' => 0,
            'jobs_total' => 0,
            'jobs_active' => 0,
            'jobs_queued' => 0,
            'jobs_pending' => 0,
            'jobs_running' => 0,
            'jobs_completed' => 0,
            'jobs_failed' => 0,
            'jobs_skipped' => 0,
            'scrape_jobs' => 0,
            'convert_jobs' => 0,
            'ingest_jobs' => 0,
        ];
    }

    private function runningCount(Collection $jobs): int
    {
        return $jobs->where('status', PipelineJob::STATUS_RUNNING)->count();
    }

    private function activeJobCount(PipelineTask $task): int
    {
        return $this->jobRepository->countForTaskWithStatuses($task->task_id, self::ACTIVE_JOB_STATUSES);
    }

    private function jobPayload(PipelineJob $job): array
    {
        return [
            'jobId' => $job->job_id,
            'taskId' => $job->task_id,
            'parentJobId' => $job->parent_job_id,
            'jobType' => $job->job_type,
            'sourceUrl' => $job->source_url,
            'localPath' => $job->local_path,
            'contentHash' => $job->content_hash,
            'status' => $job->status,
            'errorMessage' => $job->error_message,
            'startedAt' => $this->dateValue($job->started_at),
            'finishedAt' => $this->dateValue($job->finished_at),
            'metadata' => $job->metadata ?? [],
        ];
    }

    private function eventsForJob(PipelineJob $job): array
    {
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        $history = is_array($metadata['events'] ?? null) ? $metadata['events'] : [];

        if ($history === []) {
            $history[] = [
                'event_type' => $metadata['latest_event_type'] ?? 'job.status',
                'event_id' => $metadata['latest_event_id'] ?? null,
                'status' => $job->status,
                'at' => $this->dateValue($job->updated_at) ?? $this->dateValue($job->started_at),
            ];
        }

        return collect($history)
            ->filter(fn (mixed $event): bool => is_array($event))
            ->map(function (array $event) use ($job): array {
                return [
                    'eventType' => $this->nullableString($event['event_type'] ?? $event['eventType'] ?? $event['event'] ?? null) ?? 'job.status',
                    'eventId' => $this->nullableString($event['event_id'] ?? $event['eventId'] ?? null),
                    'taskId' => $job->task_id,
                    'jobId' => $job->job_id,
                    'jobType' => $job->job_type,
                    'status' => $this->nullableString($event['status'] ?? null) ?? $job->status,
                    'sourceUrl' => $job->source_url,
                    'localPath' => $job->local_path,
                    'errorMessage' => $job->error_message,
                    'at' => $this->nullableString($event['at'] ?? $event['created_at'] ?? $event['createdAt'] ?? null)
                        ?? $this->dateValue($job->updated_at)
                        ?? $this->dateValue($job->started_at),
                ];
            })
            ->all();
    }

    private function terminalStatus(string $status): bool
    {
        return in_array($status, PipelineJob::TERMINAL_STATUSES, true);
    }

    private function normalizeJobStatus(mixed $status): string
    {
        $status = $this->nullableString($status) ?? PipelineJob::STATUS_QUEUED;

        return match ($status) {
            'pending' => PipelineJob::STATUS_QUEUED,
            'received',
            'processing' => PipelineJob::STATUS_RUNNING,
            'partial',
            'cancel_requested',
            'cancelled' => PipelineJob::STATUS_FAILED,
            PipelineJob::STATUS_QUEUED,
            PipelineJob::STATUS_RUNNING,
            PipelineJob::STATUS_COMPLETED,
            PipelineJob::STATUS_SKIPPED,
            PipelineJob::STATUS_FAILED => $status,
            default => PipelineJob::STATUS_FAILED,
        };
    }

    private function appendMetadataEvent(PipelineTask $task, string $event): array
    {
        $metadata = $task->metadata ?? [];
        $events = is_array($metadata['events'] ?? null) ? $metadata['events'] : [];
        $events[] = [
            'event' => $event,
            'at' => now()->toIso8601String(),
        ];
        $metadata['events'] = $events;

        return $metadata;
    }

    private function resolveUrls(array $input): array
    {
        $urls = $this->stringList($input['urls'] ?? []);
        if ($urls === []) {
            $singleUrl = $this->nullableString($input['source_url'] ?? $input['sourceUrl'] ?? null);
            if ($singleUrl !== null) {
                $urls[] = $singleUrl;
            }
        }

        $path = $this->nullableString($input['sitemap_path'] ?? $input['sitemapPath'] ?? null);
        if ($path !== null) {
            $urls = array_merge($urls, $this->urlsFromSitemapText((string) @file_get_contents($path)));
        }

        $sitemapUrl = $this->nullableString($input['sitemap_url'] ?? $input['sitemapUrl'] ?? null);
        if ($sitemapUrl !== null && $urls === []) {
            try {
                $response = Http::timeout(30)->retry(1, 250, throw: false)->get($sitemapUrl);
                if ($response->successful()) {
                    $urls = array_merge($urls, $this->urlsFromSitemapText($response->body()));
                }
            } catch (Throwable $exception) {
                Log::warning('Unable to load remote sitemap for pipeline task.', [
                    'sitemap_url' => $sitemapUrl,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        return array_values(array_unique(array_filter(
            array_map(fn (string $url) => $this->normalizeUrl($url), $urls),
            static fn (?string $url) => $url !== null,
        )));
    }

    private function urlsFromSitemapText(string $text): array
    {
        if (trim($text) === '') {
            return [];
        }

        $json = json_decode($text, true);
        if (is_array($json)) {
            return $this->urlsFromJson($json);
        }

        if (preg_match_all('/<loc>\s*([^<]+)\s*<\/loc>/i', $text, $matches) === 1) {
            return array_map('html_entity_decode', $matches[1]);
        }

        return [];
    }

    private function urlsFromJson(array $value): array
    {
        $urls = [];
        array_walk_recursive($value, static function (mixed $item, mixed $key) use (&$urls): void {
            if (is_string($item) && in_array((string) $key, ['url', 'loc', 'source_url', 'sourceUrl'], true)) {
                $urls[] = $item;
            }
        });

        return $urls;
    }

    private function normalizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $url) !== 1) {
            $url = 'https://' . ltrim($url, '/');
        }

        return $url;
    }

    private function taskId(array $input): string
    {
        $provided = $this->nullableString($input['task_id'] ?? $input['taskId'] ?? null);

        return $provided ?? 'task_' . Str::uuid()->toString();
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(fn (mixed $item) => $this->nullableString($item), $value),
            static fn (?string $item) => $item !== null,
        ));
    }

    private function dateInput(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_scalar($value) && trim((string) $value) !== '') {
            return Carbon::parse((string) $value);
        }

        return null;
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return $value ? (string) $value : null;
    }
}
