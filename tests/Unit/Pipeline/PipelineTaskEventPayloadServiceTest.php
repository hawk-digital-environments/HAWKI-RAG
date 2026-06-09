<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Tasks\PipelineTaskEventPayloadService;
use Tests\TestCase;

class PipelineTaskEventPayloadServiceTest extends TestCase
{
    public function test_it_selects_retry_event_types_for_known_job_types(): void
    {
        $service = app(PipelineTaskEventPayloadService::class);

        $this->assertSame(
            PipelineEvent::SCRAPE_REQUESTED,
            $service->retryEventType(new PipelineJob(['job_type' => PipelineJob::TYPE_SCRAPE])),
        );
        $this->assertSame(
            PipelineEvent::FILE_DISCOVERED,
            $service->retryEventType(new PipelineJob(['job_type' => PipelineJob::TYPE_CONVERT])),
        );
        $this->assertSame(
            PipelineEvent::FILE_CONVERTED,
            $service->retryEventType(new PipelineJob(['job_type' => PipelineJob::TYPE_INGEST])),
        );
        $this->assertNull($service->retryEventType(new PipelineJob(['job_type' => 'unknown'])));
    }

    public function test_it_uses_original_source_event_type_for_ingest_retries(): void
    {
        $service = app(PipelineTaskEventPayloadService::class);
        $job = new PipelineJob([
            'job_type' => PipelineJob::TYPE_INGEST,
            'metadata' => [
                'source_event_type' => PipelineEvent::PAGE_SCRAPED,
            ],
        ]);

        $this->assertSame(PipelineEvent::PAGE_SCRAPED, $service->retryEventType($job));
    }

    public function test_it_builds_standard_event_payloads_for_jobs(): void
    {
        $service = app(PipelineTaskEventPayloadService::class);
        $task = $this->task();
        $job = new PipelineJob([
            'job_id' => 'scrape-job',
            'task_id' => 'task-events',
            'parent_job_id' => null,
            'job_type' => PipelineJob::TYPE_SCRAPE,
            'source_url' => 'https://example.test/page',
            'local_path' => '/tmp/page.md',
            'content_hash' => 'hash-page',
            'status' => PipelineJob::STATUS_QUEUED,
            'metadata' => ['max_pages' => 2],
        ]);

        $payload = $service->forJob($task, $job, PipelineEvent::SCRAPE_REQUESTED);

        $this->assertSame('task-events', $payload['task_id']);
        $this->assertSame('scrape-job', $payload['job_id']);
        $this->assertSame('dataset-events', $payload['dataset_id']);
        $this->assertSame(PipelineJob::TYPE_SCRAPE, $payload['job_type']);
        $this->assertSame('https://example.test/page', $payload['source_url']);
        $this->assertSame('/tmp/page.md', $payload['local_path']);
        $this->assertSame('hash-page', $payload['content_hash']);
        $this->assertSame(PipelineJob::STATUS_QUEUED, $payload['status']);
        $this->assertSame(['max_pages' => 2], $payload['metadata']);
    }

    public function test_it_restores_source_job_identity_for_ingest_retries(): void
    {
        $service = app(PipelineTaskEventPayloadService::class);
        $task = $this->task();
        $job = new PipelineJob([
            'job_id' => 'ingest-job',
            'task_id' => 'task-events',
            'parent_job_id' => 'convert-parent',
            'job_type' => PipelineJob::TYPE_INGEST,
            'source_url' => 'https://example.test/file.pdf',
            'local_path' => '/tmp/file.md',
            'content_hash' => 'hash-file',
            'status' => PipelineJob::STATUS_QUEUED,
            'metadata' => [
                'source_job_id' => 'convert-source',
                'source_event_type' => PipelineEvent::FILE_CONVERTED,
            ],
        ]);

        $payload = $service->forJob($task, $job, PipelineEvent::FILE_CONVERTED);

        $this->assertSame('convert-source', $payload['job_id']);
        $this->assertSame('convert-parent', $payload['parent_job_id']);
        $this->assertSame(PipelineJob::TYPE_CONVERT, $payload['job_type']);
    }

    public function test_ingest_retry_payload_falls_back_to_parent_job_id(): void
    {
        $service = app(PipelineTaskEventPayloadService::class);
        $task = $this->task();
        $job = new PipelineJob([
            'job_id' => 'ingest-job',
            'task_id' => 'task-events',
            'parent_job_id' => 'scrape-parent',
            'job_type' => PipelineJob::TYPE_INGEST,
            'status' => PipelineJob::STATUS_QUEUED,
            'metadata' => [],
        ]);

        $payload = $service->forJob($task, $job, PipelineEvent::PAGE_SCRAPED);

        $this->assertSame('scrape-parent', $payload['job_id']);
        $this->assertSame(PipelineJob::TYPE_SCRAPE, $payload['job_type']);
    }

    private function task(): PipelineTask
    {
        return new PipelineTask([
            'task_id' => 'task-events',
            'dataset_id' => 'dataset-events',
        ]);
    }
}
