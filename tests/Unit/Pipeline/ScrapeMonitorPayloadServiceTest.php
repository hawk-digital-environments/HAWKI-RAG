<?php
declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\EventHandlers\ScrapeMonitorEventHandler;
use App\Services\Pipeline\PipelineEvent;
use App\Services\Pipeline\ScrapeMonitorPayloadService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ScrapeMonitorPayloadServiceTest extends TestCase
{
    public function test_it_builds_page_scraped_event_from_completed_scrape_job(): void
    {
        $job = $this->scrapeJob();

        $event = app(ScrapeMonitorPayloadService::class)->pageScrapedEvent($job, '/app/shared/task/page');

        $this->assertSame(PipelineEvent::PAGE_SCRAPED, $event['event_type']);
        $this->assertSame('task-monitor-payload', $event['task_id']);
        $this->assertSame('scrape-monitor-payload', $event['job_id']);
        $this->assertSame('/app/shared/task/page', $event['local_path']);
        $this->assertSame('dataset-monitor-payload', $event['dataset_id']);
        $this->assertSame(ScrapeMonitorEventHandler::class, $event['metadata']['source']);
    }

    public function test_it_builds_file_discovered_payload_with_hash_and_convert_job_id(): void
    {
        $root = sys_get_temp_dir() . '/hawki-monitor-payload-' . uniqid();
        $path = $root . '/download.pdf';
        File::ensureDirectoryExists($root);
        File::put($path, '%PDF monitor payload');
        $job = $this->scrapeJob();

        try {
            $payload = app(ScrapeMonitorPayloadService::class)->fileDiscoveredPayload($job, $root, $path);

            $this->assertSame('task-monitor-payload', $payload['task_id']);
            $this->assertSame('convert_' . substr(hash('sha256', 'task-monitor-payload|' . $path), 0, 24), $payload['job_id']);
            $this->assertSame('scrape-monitor-payload', $payload['parent_job_id']);
            $this->assertSame($path, $payload['local_path']);
            $this->assertSame(hash_file('sha256', $path), $payload['content_hash']);
            $this->assertSame(ScrapeMonitorEventHandler::class, $payload['metadata']['source']);
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_it_builds_failed_source_event_payload(): void
    {
        $job = $this->scrapeJob();

        $event = app(ScrapeMonitorPayloadService::class)->failedSourceEvent($job, [
            'dataset_id' => 'fallback-dataset',
            'source_url' => 'https://fallback.test',
            'local_path' => '/fallback',
            'content_hash' => 'fallback-hash',
        ], 'Crawler crashed.', [
            'crawlerStatus' => 'failed',
        ]);

        $this->assertSame(PipelineEvent::SCRAPE_REQUESTED, $event['event_type']);
        $this->assertSame('scrape-monitor-payload', $event['job_id']);
        $this->assertSame('failed', $event['status']);
        $this->assertSame('Crawler crashed.', $event['metadata']['error_message']);
        $this->assertSame('failed', $event['metadata']['crawlerStatus']);
        $this->assertSame(ScrapeMonitorEventHandler::class, $event['metadata']['source']);
    }

    private function scrapeJob(): PipelineJob
    {
        $task = new PipelineTask([
            'task_id' => 'task-monitor-payload',
            'dataset_id' => 'dataset-monitor-payload',
        ]);

        $job = new PipelineJob([
            'job_id' => 'scrape-monitor-payload',
            'task_id' => 'task-monitor-payload',
            'parent_job_id' => null,
            'source_url' => 'https://example.test/monitor',
            'local_path' => null,
            'content_hash' => 'scrape-hash',
            'metadata' => [
                'dataset_id' => 'dataset-monitor-payload',
            ],
        ]);
        $job->setRelation('task', $task);

        return $job;
    }
}
