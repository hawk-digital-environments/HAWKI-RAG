<?php

namespace App\Services\Pipeline;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Models\ScrapedElement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PipelineTaskService
{
    private const ACTIVE_JOB_STATUSES = [
        PipelineJob::STATUS_PENDING,
        PipelineJob::STATUS_RUNNING,
        PipelineJob::STATUS_CANCEL_REQUESTED,
        'received',
        'processing',
    ];

    public function __construct(
        private readonly PrefectFlowService $prefect,
    ) {
    }

    public function start(array $input): PipelineTask
    {
        $task = DB::transaction(function () use ($input): PipelineTask {
            $task = PipelineTask::query()->create([
                'task_id' => $this->taskId($input),
                'dataset_id' => $this->nullableString($input['dataset_id'] ?? $input['datasetId'] ?? null),
                'profile_id' => $this->nullableString($input['profile_id'] ?? $input['profileId'] ?? null),
                'sitemap_url' => $this->nullableString($input['sitemap_url'] ?? $input['sitemapUrl'] ?? null),
                'sitemap_path' => $this->nullableString($input['sitemap_path'] ?? $input['sitemapPath'] ?? null),
                'status' => PipelineTask::STATUS_RUNNING,
                'started_at' => Carbon::now(),
                'counters' => $this->defaultCounters(),
                'metadata' => [
                    'request' => $input,
                    'orchestration' => 'prefect',
                    'rabbitmq' => [
                        'event_bus' => true,
                        'replaced_by_prefect' => false,
                    ],
                ],
            ]);

            foreach ($this->resolveUrls($input) as $url) {
                $this->createScrapeJob($task, $url);
            }

            return $this->refreshCounters($task);
        });

        $this->publishTaskEvent($task, 'task_started_routing_key', 'task.started');
        $prefect = $this->prefect->startFlowRun($task->task_id);

        $metadata = $task->metadata ?? [];
        $metadata['prefect'] = $prefect;
        $task->forceFill(['metadata' => $metadata])->save();

        return $this->refreshCounters($task);
    }

    public function show(string $taskId): ?array
    {
        $task = PipelineTask::query()
            ->with(['jobs' => fn ($query) => $query->orderBy('id')])
            ->where('task_id', $taskId)
            ->first();

        if (!$task) {
            return null;
        }

        $task = $this->refreshCounters($task);

        return [
            'taskId' => $task->task_id,
            'datasetId' => $task->dataset_id,
            'profileId' => $task->profile_id,
            'sitemapUrl' => $task->sitemap_url,
            'sitemapPath' => $task->sitemap_path,
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

    public function jobs(string $taskId): array
    {
        return PipelineJob::query()
            ->where('task_id', $taskId)
            ->orderBy('id')
            ->get()
            ->map(fn (PipelineJob $job) => $this->jobPayload($job))
            ->all();
    }

    public function upsertJob(string $taskId, array $input): PipelineJob
    {
        $task = PipelineTask::query()->where('task_id', $taskId)->firstOrFail();
        $status = $this->nullableString($input['status'] ?? null) ?? PipelineJob::STATUS_PENDING;
        $finishedAt = $this->terminalStatus($status) ? Carbon::now() : null;

        $job = PipelineJob::query()->updateOrCreate(
            ['job_id' => $this->nullableString($input['job_id'] ?? $input['jobId'] ?? null) ?? (string) Str::uuid()],
            [
                'task_id' => $task->task_id,
                'parent_job_id' => $this->nullableString($input['parent_job_id'] ?? $input['parentJobId'] ?? null),
                'job_type' => $this->nullableString($input['job_type'] ?? $input['jobType'] ?? null),
                'source_url' => $this->nullableString($input['source_url'] ?? $input['sourceUrl'] ?? null),
                'local_path' => $this->nullableString($input['local_path'] ?? $input['localPath'] ?? null),
                'content_hash' => $this->nullableString($input['content_hash'] ?? $input['contentHash'] ?? null),
                'status' => $status,
                'started_at' => $this->dateInput($input['started_at'] ?? $input['startedAt'] ?? null),
                'completed_at' => $finishedAt,
                'finished_at' => $finishedAt,
                'metadata' => is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
            ],
        );

        $this->refreshCounters($task);

        return $job;
    }

    public function cancel(string $taskId): ?PipelineTask
    {
        $task = PipelineTask::query()->where('task_id', $taskId)->first();
        if (!$task) {
            return null;
        }

        $task->forceFill([
            'status' => PipelineTask::STATUS_CANCEL_REQUESTED,
            'metadata' => $this->appendMetadataEvent($task, 'cancel_requested'),
        ])->save();

        PipelineJob::query()
            ->where('task_id', $task->task_id)
            ->whereIn('status', self::ACTIVE_JOB_STATUSES)
            ->update([
                'status' => PipelineJob::STATUS_CANCEL_REQUESTED,
                'updated_at' => Carbon::now(),
            ]);

        $this->publishTaskEvent($task, 'task_cancel_requested_routing_key', 'task.cancel_requested');

        return $this->refreshCounters($task);
    }

    public function resume(string $taskId): ?PipelineTask
    {
        $task = PipelineTask::query()->where('task_id', $taskId)->first();
        if (!$task) {
            return null;
        }

        $task->forceFill([
            'status' => PipelineTask::STATUS_RUNNING,
            'finished_at' => null,
            'metadata' => $this->appendMetadataEvent($task, 'resumed'),
        ])->save();

        PipelineJob::query()
            ->where('task_id', $task->task_id)
            ->where('status', PipelineJob::STATUS_CANCEL_REQUESTED)
            ->update([
                'status' => PipelineJob::STATUS_PENDING,
                'updated_at' => Carbon::now(),
            ]);

        $this->publishTaskEvent($task, 'task_resumed_routing_key', 'task.resumed');

        return $this->refreshCounters($task);
    }

    public function retry(string $taskId): ?PipelineTask
    {
        $task = PipelineTask::query()->where('task_id', $taskId)->first();
        if (!$task) {
            return null;
        }

        $jobs = PipelineJob::query()
            ->where('task_id', $task->task_id)
            ->whereIn('status', [PipelineJob::STATUS_FAILED, PipelineJob::STATUS_CANCEL_REQUESTED, PipelineJob::STATUS_CANCELLED])
            ->get();

        foreach ($jobs as $job) {
            $metadata = $job->metadata ?? [];
            $metadata['retry_count'] = (int) ($metadata['retry_count'] ?? 0) + 1;
            $metadata['retried_at'] = now()->toIso8601String();

            $job->forceFill([
                'status' => PipelineJob::STATUS_PENDING,
                'completed_at' => null,
                'finished_at' => null,
                'metadata' => $metadata,
            ])->save();

            if ($job->job_type === PipelineJob::TYPE_SCRAPE) {
                $this->publishScrapeJobQueued($task, $job);
            }
        }

        $task->forceFill([
            'status' => PipelineTask::STATUS_RUNNING,
            'finished_at' => null,
            'metadata' => $this->appendMetadataEvent($task, 'retry_requested'),
        ])->save();

        $this->publishTaskEvent($task, 'task_retry_requested_routing_key', 'task.retry_requested');

        return $this->refreshCounters($task);
    }

    public function completeIfIdle(string $taskId): ?PipelineTask
    {
        $task = PipelineTask::query()->with('jobs')->where('task_id', $taskId)->first();
        if (!$task) {
            return null;
        }

        if ($task->status === PipelineTask::STATUS_CANCEL_REQUESTED) {
            $task->forceFill([
                'status' => PipelineTask::STATUS_CANCELLED,
                'finished_at' => Carbon::now(),
                'metadata' => $this->appendMetadataEvent($task, 'cancelled_by_prefect'),
            ])->save();

            return $this->refreshCounters($task);
        }

        if ($this->activeJobCount($task) > 0 || in_array($task->status, [PipelineTask::STATUS_PAUSED, PipelineTask::STATUS_CANCELLED], true)) {
            return $this->refreshCounters($task);
        }

        $hasFailed = $task->jobs->contains(fn (PipelineJob $job) => $job->status === PipelineJob::STATUS_FAILED);
        $task->forceFill([
            'status' => $hasFailed ? PipelineTask::STATUS_FAILED : PipelineTask::STATUS_COMPLETED,
            'finished_at' => Carbon::now(),
            'metadata' => $this->appendMetadataEvent($task, $hasFailed ? 'failed_by_prefect' : 'completed_by_prefect'),
        ])->save();

        return $this->refreshCounters($task);
    }

    public function updateStatus(string $taskId, string $status, array $metadata = []): ?PipelineTask
    {
        $task = PipelineTask::query()->where('task_id', $taskId)->first();
        if (!$task) {
            return null;
        }

        $finishedAt = in_array($status, [
            PipelineTask::STATUS_COMPLETED,
            PipelineTask::STATUS_FAILED,
            PipelineTask::STATUS_CANCELLED,
        ], true) ? Carbon::now() : null;

        $task->forceFill([
            'status' => $status,
            'finished_at' => $finishedAt,
            'metadata' => array_merge($task->metadata ?? [], $metadata),
        ])->save();

        return $this->refreshCounters($task);
    }

    public function refreshCounters(PipelineTask $task): PipelineTask
    {
        $jobs = PipelineJob::query()->where('task_id', $task->task_id)->get();
        $byStatus = $jobs->countBy('status');
        $byType = $jobs->countBy('job_type');

        $task->forceFill([
            'counters' => [
                'jobs_total' => $jobs->count(),
                'jobs_active' => $jobs->whereIn('status', self::ACTIVE_JOB_STATUSES)->count(),
                'jobs_pending' => (int) ($byStatus[PipelineJob::STATUS_PENDING] ?? 0),
                'jobs_running' => (int) ($byStatus[PipelineJob::STATUS_RUNNING] ?? 0)
                    + (int) ($byStatus['received'] ?? 0)
                    + (int) ($byStatus['processing'] ?? 0),
                'jobs_completed' => (int) ($byStatus[PipelineJob::STATUS_COMPLETED] ?? 0),
                'jobs_failed' => (int) ($byStatus[PipelineJob::STATUS_FAILED] ?? 0),
                'jobs_skipped' => (int) ($byStatus[PipelineJob::STATUS_SKIPPED] ?? 0),
                'scrape_jobs' => (int) ($byType[PipelineJob::TYPE_SCRAPE] ?? 0),
                'convert_jobs' => (int) ($byType[PipelineJob::TYPE_CONVERT] ?? 0),
                'ingest_jobs' => (int) ($byType[PipelineJob::TYPE_INGEST] ?? 0),
                'graph_jobs' => (int) ($byType[PipelineJob::TYPE_GRAPH] ?? 0),
                'discovered' => $jobs
                    ->whereIn('job_type', [PipelineJob::TYPE_SCRAPE, PipelineJob::TYPE_CONVERT])
                    ->count(),
                'scraped' => $jobs
                    ->where('job_type', PipelineJob::TYPE_SCRAPE)
                    ->where('status', PipelineJob::STATUS_COMPLETED)
                    ->count(),
                'skipped' => (int) ($byStatus[PipelineJob::STATUS_SKIPPED] ?? 0),
                'converted' => $jobs
                    ->where('job_type', PipelineJob::TYPE_CONVERT)
                    ->where('status', PipelineJob::STATUS_COMPLETED)
                    ->count(),
                'ingested' => $jobs
                    ->where('job_type', PipelineJob::TYPE_INGEST)
                    ->where('status', PipelineJob::STATUS_COMPLETED)
                    ->count(),
                'graph_updated' => $jobs
                    ->where('job_type', PipelineJob::TYPE_GRAPH)
                    ->where('status', PipelineJob::STATUS_COMPLETED)
                    ->count(),
                'failed' => (int) ($byStatus[PipelineJob::STATUS_FAILED] ?? 0),
            ],
        ])->save();

        return $task->refresh();
    }

    private function createScrapeJob(PipelineTask $task, string $url): PipelineJob
    {
        $contentHash = hash('sha256', $url);
        $alreadyScraped = $this->alreadyScraped($url, $contentHash);
        $status = $alreadyScraped ? PipelineJob::STATUS_SKIPPED : PipelineJob::STATUS_PENDING;
        $now = Carbon::now();

        $job = PipelineJob::query()->create([
            'job_id' => ($alreadyScraped ? 'skipped_' : 'scrape_') . substr(hash('sha256', $task->task_id . '|' . $url), 0, 24),
            'task_id' => $task->task_id,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => $url,
            'content_hash' => $contentHash,
            'status' => $status,
            'started_at' => $now,
            'completed_at' => $alreadyScraped ? $now : null,
            'finished_at' => $alreadyScraped ? $now : null,
            'metadata' => [
                'reason' => $alreadyScraped ? 'URL was already scraped by Laravel.' : 'Queued for scraper worker through RabbitMQ.',
                'profile_id' => $task->profile_id,
                'dataset_id' => $task->dataset_id,
            ],
        ]);

        if ($alreadyScraped) {
            app(PipelineStateService::class)->skipStage($job->job_id, PipelineStateService::STAGE_SCRAPE, [
                'source_url' => $url,
                'metadata' => [
                    'source' => 'PipelineTaskService',
                    'reason' => 'URL was already scraped by Laravel.',
                ],
                'counts' => [
                    'skipped' => 1,
                    'totalPages' => 1,
                ],
            ]);
        } else {
            $this->publishScrapeJobQueued($task, $job);
        }

        return $job;
    }

    private function publishScrapeJobQueued(PipelineTask $task, PipelineJob $job): void
    {
        $this->publishEvent(
            (string) config('communication.rabbitmq.pipeline_events.scrape_job_queued_routing_key', 'scrape.job.queued'),
            [
                'task_id' => $task->task_id,
                'job_id' => $job->job_id,
                'parent_job_id' => $job->parent_job_id,
                'dataset_id' => $task->dataset_id,
                'profile_id' => $task->profile_id,
                'job_type' => PipelineJob::TYPE_SCRAPE,
                'source_url' => $job->source_url,
                'local_path' => $job->local_path,
                'content_hash' => $job->content_hash,
                'status' => $job->status,
                'sitemap_url' => $task->sitemap_url,
                'sitemap_path' => $task->sitemap_path,
                'metadata' => array_merge($job->metadata ?? [], [
                    'sitemap_url' => $task->sitemap_url,
                    'sitemap_path' => $task->sitemap_path,
                ]),
            ],
        );
    }

    private function publishTaskEvent(PipelineTask $task, string $routingKeyConfig, string $eventType): void
    {
        $this->publishEvent(
            (string) config("communication.rabbitmq.pipeline_events.{$routingKeyConfig}", $eventType),
            [
                'task_id' => $task->task_id,
                'job_id' => $task->task_id,
                'parent_job_id' => null,
                'dataset_id' => $task->dataset_id,
                'profile_id' => $task->profile_id,
                'job_type' => 'task',
                'source_url' => $task->sitemap_url,
                'local_path' => $task->sitemap_path,
                'content_hash' => null,
                'status' => $task->status,
                'counters' => $task->counters ?? [],
                'metadata' => [
                    'task_metadata' => $task->metadata ?? [],
                    'counters' => $task->counters ?? [],
                ],
            ],
        );
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

    private function alreadyScraped(string $url, string $contentHash): bool
    {
        return ScrapedElement::query()
            ->where('page_url_hash', $contentHash)
            ->orWhere('page_url', $url)
            ->exists()
            || PipelineJob::query()
                ->where('source_url', $url)
                ->whereIn('status', [PipelineJob::STATUS_COMPLETED, PipelineJob::STATUS_SKIPPED])
                ->exists();
    }

    private function activeJobCount(PipelineTask $task): int
    {
        return PipelineJob::query()
            ->where('task_id', $task->task_id)
            ->whereIn('job_type', [
                PipelineJob::TYPE_SCRAPE,
                PipelineJob::TYPE_CONVERT,
                PipelineJob::TYPE_INGEST,
                PipelineJob::TYPE_GRAPH,
            ])
            ->whereIn('status', self::ACTIVE_JOB_STATUSES)
            ->count();
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
            'startedAt' => $this->dateValue($job->started_at),
            'finishedAt' => $this->dateValue($job->finished_at ?? $job->completed_at),
            'metadata' => $job->metadata ?? [],
        ];
    }

    private function defaultCounters(): array
    {
        return [
            'jobs_total' => 0,
            'jobs_active' => 0,
            'jobs_pending' => 0,
            'jobs_running' => 0,
            'jobs_completed' => 0,
            'jobs_failed' => 0,
            'jobs_skipped' => 0,
            'scrape_jobs' => 0,
            'convert_jobs' => 0,
            'ingest_jobs' => 0,
            'graph_jobs' => 0,
            'discovered' => 0,
            'scraped' => 0,
            'skipped' => 0,
            'converted' => 0,
            'ingested' => 0,
            'graph_updated' => 0,
            'failed' => 0,
        ];
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

    private function terminalStatus(string $status): bool
    {
        return in_array($status, [
            PipelineJob::STATUS_COMPLETED,
            PipelineJob::STATUS_FAILED,
            PipelineJob::STATUS_SKIPPED,
            PipelineJob::STATUS_PARTIAL,
            PipelineJob::STATUS_CANCELLED,
        ], true);
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
