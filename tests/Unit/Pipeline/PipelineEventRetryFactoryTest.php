<?php
declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\PipelineEvent;
use App\Services\Pipeline\PipelineEventRetryFactory;
use Illuminate\Support\Carbon;
use RuntimeException;
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
        ], new RuntimeException('Worker failed.'));

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
        ], new RuntimeException('Still failing.'));

        $this->assertNull($retry);
    }

    public function test_it_builds_delayed_and_recovery_retry_events(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-09T12:00:00+00:00'));

        $factory = app(PipelineEventRetryFactory::class);
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

        Carbon::setTestNow();
    }
}
