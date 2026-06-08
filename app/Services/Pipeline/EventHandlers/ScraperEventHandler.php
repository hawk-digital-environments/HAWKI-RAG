<?php

namespace App\Services\Pipeline\EventHandlers;

use App\Models\PipelineJob;
use App\Services\Pipeline\PipelineEvent;
use App\Services\Pipeline\PipelineEventBus;
use App\Services\Pipeline\PipelineEventStateService;
use App\Services\Pipeline\Repositories\PipelineJobRepository;
use App\Services\Pipeline\Repositories\PipelineScrapeHistoryRepository;
use App\Services\ScrapeService\Data\ScrapeJobRequest;
use App\Services\ScrapeService\ScraperPipelineService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ScraperEventHandler implements PipelineEventHandler
{
    public function __construct(
        private readonly PipelineEventBus $events,
        private readonly PipelineEventStateService $state,
        private readonly PipelineJobRepository $jobs,
        private readonly PipelineScrapeHistoryRepository $scrapeHistory,
        private readonly ScraperPipelineService $scraper,
    ) {
    }

    public function eventTypes(): array
    {
        return [
            PipelineEvent::SCRAPE_REQUESTED,
        ];
    }

    public function handle(array $event): void
    {
        $this->handleScrapeRequested($event);
    }

    public function failed(array $event, Throwable $error, int $retryCount, int $maxRetries): void
    {
        $retryable = $retryCount < $maxRetries;
        $this->state->upsertJob($event, $retryable ? PipelineJob::STATUS_PENDING : PipelineJob::STATUS_FAILED, [
            'retry_count' => $retryCount,
            'max_retries' => $maxRetries,
            'retry_scheduled' => $retryable,
            'error_type' => class_basename($error),
            'error_message' => $error->getMessage(),
        ]);
    }

    private function handleScrapeRequested(array $event): void
    {
        $event = PipelineEvent::normalize(PipelineEvent::SCRAPE_REQUESTED, $event);
        $url = (string) $event['source_url'];
        if ($url === '') {
            throw new \InvalidArgumentException('Scrape event requires source_url.');
        }

        $contentHash = (string) ($event['content_hash'] ?: hash('sha256', $url));
        $event['content_hash'] = $contentHash;

        $existing = $this->jobs->findByJobId((string) $event['job_id']);
        if ($existing && in_array($existing->status, [PipelineJob::STATUS_RUNNING, PipelineJob::STATUS_COMPLETED, PipelineJob::STATUS_SKIPPED], true)) {
            Log::info('pipeline.event.scrape.duplicate_ignored', [
                'task_id' => $event['task_id'],
                'job_id' => $event['job_id'],
                'status' => $existing->status,
            ]);
            return;
        }

        if ($this->scrapeHistory->hasCompletedScraperOutput($url, $contentHash)) {
            $this->state->upsertJob($event, PipelineJob::STATUS_SKIPPED, [
                'reason' => 'URL/content_hash was already scraped.',
            ]);
            $this->events->publish(PipelineEvent::PAGE_SCRAPED, array_merge($event, [
                'status' => PipelineJob::STATUS_SKIPPED,
                'metadata' => array_merge($event['metadata'], [
                    'reason' => 'URL/content_hash was already scraped.',
                ]),
            ]));
            return;
        }

        $this->state->upsertJob($event, PipelineJob::STATUS_RUNNING, [
            'source' => self::class,
            'stage' => 'scrape_submitted',
        ]);

        $outputDir = $event['local_path'] ?: $this->outputDirFor($event);
        File::ensureDirectoryExists($outputDir);

        $result = $this->scraper->execute(ScrapeJobRequest::fromArray([
            'job_id' => $event['job_id'],
            'url' => $url,
            'label' => $event['metadata']['label'] ?? $this->labelForUrl($url),
            'output_dir' => $outputDir,
            'max_pages' => $event['metadata']['max_pages'] ?? 1,
            'skip_images' => $event['metadata']['skip_images'] ?? false,
            'max_concurrency' => $event['metadata']['max_concurrency'] ?? 1,
            'max_rpm' => $event['metadata']['max_rpm'] ?? 60,
            'discoveryMode' => $event['metadata']['discovery_mode'] ?? false,
        ]));

        if (!$result->success) {
            throw new \RuntimeException($result->errors[0]['message'] ?? $result->errors[0] ?? 'Scraper pipeline failed.');
        }

        $this->events->publish(PipelineEvent::SCRAPE_MONITOR_REQUESTED, array_merge($event, [
            'local_path' => $outputDir,
            'status' => PipelineJob::STATUS_RUNNING,
            'metadata' => array_merge($event['metadata'], [
                'source' => self::class,
                'monitor_mode' => 'rabbitmq',
                'monitor_attempt' => 0,
            ]),
        ]));

        Log::info('pipeline.event.scrape.submitted', [
            'task_id' => $event['task_id'],
            'job_id' => $event['job_id'],
            'source_url' => $url,
        ]);
    }

    private function outputDirFor(array $event): string
    {
        return rtrim((string) config('scraper.storage_path'), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . (string) $event['task_id']
            . DIRECTORY_SEPARATOR
            . (string) $event['job_id'];
    }

    private function labelForUrl(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return Str::slug(is_string($host) && $host !== '' ? $host : 'scrape-job') ?: 'scrape-job';
    }
}
