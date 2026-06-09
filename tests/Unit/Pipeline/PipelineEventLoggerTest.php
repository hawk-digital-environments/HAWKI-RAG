<?php
declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\PipelineEvent;
use App\Services\Pipeline\PipelineEventLogger;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class PipelineEventLoggerTest extends TestCase
{
    public function test_it_logs_normalized_pipeline_event_context(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->with('pipeline.event', Mockery::on(function (array $context): bool {
                $this->assertSame('publish', $context['action']);
                $this->assertSame(PipelineEvent::PAGE_SCRAPED, $context['event_type']);
                $this->assertSame('task-event-log', $context['task_id']);
                $this->assertSame('job-event-log', $context['job_id']);
                $this->assertSame(0, $context['retry_count']);

                return true;
            }));

        app(PipelineEventLogger::class)->log('publish', [
            'event_type' => PipelineEvent::PAGE_SCRAPED,
            'task_id' => 'task-event-log',
            'job_id' => 'job-event-log',
        ]);
    }

    public function test_it_logs_worker_failures_with_retry_context(): void
    {
        $error = new \RuntimeException('Worker failed.');

        Log::shouldReceive('warning')
            ->once()
            ->with('Pipeline event worker failed', Mockery::on(function (array $context) use ($error): bool {
                $this->assertSame(PipelineEvent::PAGE_SCRAPED, $context['event_type']);
                $this->assertSame('task-worker-log', $context['task_id']);
                $this->assertSame('job-worker-log', $context['job_id']);
                $this->assertSame(2, $context['retry_count']);
                $this->assertSame(3, $context['max_retries']);
                $this->assertSame('Worker failed.', $context['error']);
                $this->assertSame($error, $context['exception']);

                return true;
            }));

        app(PipelineEventLogger::class)->workerFailed([
            'event_type' => PipelineEvent::PAGE_SCRAPED,
            'task_id' => 'task-worker-log',
            'job_id' => 'job-worker-log',
        ], 2, 3, $error);
    }
}
