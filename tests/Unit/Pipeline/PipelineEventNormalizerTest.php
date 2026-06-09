<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Models\PipelineJob;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventNormalizer;
use App\Services\Pipeline\Events\PipelineEventTypeRegistry;
use Symfony\Component\Clock\MockClock;
use Tests\TestCase;

class PipelineEventNormalizerTest extends TestCase
{
    public function test_it_normalizes_payload_aliases_and_generates_deterministic_job_id(): void
    {
        $normalizer = new PipelineEventNormalizer(
            app(PipelineEventTypeRegistry::class),
            new MockClock('2026-06-09T12:00:00+00:00'),
        );

        $event = $normalizer->normalize(PipelineEvent::PAGE_SCRAPED, [
            'taskId' => 'task-normalizer',
            'sourceUrl' => 'https://example.test/page',
            'localPath' => '/app/shared/page',
            'contentHash' => 'hash-page',
            'metadata' => 'invalid',
            'retry_count' => -1,
        ]);

        $this->assertSame(PipelineEvent::PAGE_SCRAPED, $event['event_type']);
        $this->assertSame('task-normalizer', $event['task_id']);
        $this->assertSame(PipelineJob::TYPE_SCRAPE, $event['job_type']);
        $this->assertStringStartsWith('scrape_', $event['job_id']);
        $this->assertSame([], $event['metadata']);
        $this->assertSame(0, $event['retry_count']);
        $this->assertSame('2026-06-09T12:00:00+00:00', $event['created_at']);
        $this->assertArrayHasKey('schema_version', $event);
    }

    public function test_static_pipeline_event_methods_delegate_to_event_services(): void
    {
        $event = PipelineEvent::normalize(PipelineEvent::FILE_DISCOVERED, [
            'task_id' => 'task-static-delegate',
            'local_path' => '/tmp/file.pdf',
        ]);

        $this->assertSame(PipelineJob::TYPE_CONVERT, $event['job_type']);
        $this->assertSame(PipelineJob::TYPE_CONVERT, PipelineEvent::jobTypeFor(PipelineEvent::FILE_DISCOVERED));
        $this->assertTrue(PipelineEvent::terminalStatus(PipelineJob::STATUS_COMPLETED));
    }

    public function test_event_type_registry_maps_job_types_and_terminal_statuses(): void
    {
        $registry = app(PipelineEventTypeRegistry::class);

        $this->assertSame(PipelineJob::TYPE_SCRAPE, $registry->jobTypeFor(PipelineEvent::SCRAPE_MONITOR_REQUESTED));
        $this->assertSame(PipelineJob::TYPE_CONVERT, $registry->jobTypeFor(PipelineEvent::FILE_CONVERTED));
        $this->assertSame(PipelineJob::TYPE_INGEST, $registry->jobTypeFor(PipelineEvent::CONTENT_INGESTED));
        $this->assertNull($registry->jobTypeFor(PipelineEvent::JOB_FAILED));
        $this->assertTrue($registry->terminalStatus(PipelineJob::STATUS_SKIPPED));
        $this->assertFalse($registry->terminalStatus(PipelineJob::STATUS_RUNNING));
    }
}
