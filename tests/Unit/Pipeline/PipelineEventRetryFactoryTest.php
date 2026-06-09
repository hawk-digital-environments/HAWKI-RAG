<?php
declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Exceptions\PipelineEventException;
use App\Services\Pipeline\PipelineEvent;
use App\Services\Pipeline\PipelineEventNormalizer;
use App\Services\Pipeline\PipelineEventRetryFactory;
use Symfony\Component\Clock\MockClock;
use Tests\TestCase;

class PipelineEventRetryFactoryTest extends TestCase
{
    public function test_it_builds_retry_events_with_error_context(): void
    {
        config()->set('communication.rabbitmq.pipeline_events.max_retries', 3);

        $retry = app(PipelineEventRetryFactory::class)->makeRetry([
            'event_type' => PipelineEvent::PAGE_SCRAPED,
            'task_id' => 'task-retry-factory',
            'job_id' => 'job-retry-factory',
            'retry_count' => 1,
            'metadata' => ['existing' => true],
        ], new \RuntimeException('Worker failed.'));

        $this->assertNotNull($retry);
        $this->assertSame(PipelineEvent::PAGE_SCRAPED, $retry['event_type']);
        $this->assertSame(2, $retry['retry_count']);
        $this->assertSame(3, $retry['max_retries']);
        $this->assertSame('RuntimeException', $retry['last_error_type']);
        $this->assertSame('Worker failed.', $retry['last_error_message']);
        $this->assertTrue($retry['metadata']['existing']);
        $this->assertSame('RuntimeException', $retry['metadata']['last_error_type']);
        $this->assertSame('Worker failed.', $retry['metadata']['last_error_message']);
    }

    public function test_it_returns_null_when_retry_limit_is_exceeded(): void
    {
        $retry = app(PipelineEventRetryFactory::class)->makeRetry([
            'event_type' => PipelineEvent::PAGE_SCRAPED,
            'retry_count' => 3,
            'max_retries' => 3,
        ], new \RuntimeException('Still failing.'));

        $this->assertNull($retry);
    }

    public function test_it_builds_delayed_and_recovery_retry_events(): void
    {
        $factory = new PipelineEventRetryFactory(
            app(PipelineEventNormalizer::class),
            new MockClock('2026-06-09T12:00:00+00:00'),
        );
        $event = PipelineEvent::normalize(PipelineEvent::SCRAPE_MONITOR_REQUESTED, [
            'task_id' => 'task-delay-factory',
            'job_id' => 'job-delay-factory',
            'metadata' => ['monitor_attempt' => 2],
        ]);

        $delayed = $factory->makeDelayed($event, 'crawl still running');
        $recovery = $factory->makeRecoveryRetry($event, 'operator retry');

        $this->assertSame('crawl still running', $delayed['metadata']['delay_reason']);
        $this->assertSame('2026-06-09T12:00:00+00:00', $delayed['metadata']['delay_requested_at']);
        $this->assertSame(2, $delayed['metadata']['monitor_attempt']);
        $this->assertSame('operator retry', $recovery['metadata']['recovery_reason']);
        $this->assertSame('2026-06-09T12:00:00+00:00', $recovery['metadata']['recovery_requested_at']);
    }

    public function test_it_rejects_delayed_events_without_event_type(): void
    {
        $this->expectException(PipelineEventException::class);
        $this->expectExceptionMessage('Delayed pipeline event publish requires event_type.');

        app(PipelineEventRetryFactory::class)->makeDelayed([], 'missing type');
    }

    public function test_it_rejects_recovery_retries_without_event_type(): void
    {
        $this->expectException(PipelineEventException::class);
        $this->expectExceptionMessage('Pipeline recovery retry requires event_type.');

        app(PipelineEventRetryFactory::class)->makeRecoveryRetry([], 'missing type');
    }
}
