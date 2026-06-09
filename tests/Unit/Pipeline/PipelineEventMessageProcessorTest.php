<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\EventHandlers\PipelineEventHandler;
use App\Services\Pipeline\Events\PipelineEvent;
use App\Services\Pipeline\Events\PipelineEventBus;
use App\Services\Pipeline\Events\PipelineEventDecoder;
use App\Services\Pipeline\Events\PipelineEventLogger;
use App\Services\Pipeline\Events\PipelineEventMessageProcessor;
use App\Support\PipelineExitCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Mockery;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\TestCase;

class PipelineEventMessageProcessorTest extends TestCase
{
    public function test_it_handles_matching_events_and_acks_the_message(): void
    {
        $event = PipelineEvent::normalize(PipelineEvent::PAGE_SCRAPED, [
            'task_id' => 'task-message-process',
            'job_id' => 'job-message-process',
        ]);

        $message = Mockery::mock(AMQPMessage::class);
        $message->shouldReceive('getBody')->once()->andReturn(json_encode($event, JSON_THROW_ON_ERROR));
        $message->shouldReceive('ack')->once();

        $handler = Mockery::mock(PipelineEventHandler::class);
        $handler->shouldReceive('eventTypes')->once()->andReturn([PipelineEvent::PAGE_SCRAPED]);
        $handler->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(fn (array $handled): bool => $handled['job_id'] === 'job-message-process'));
        $handler->shouldNotReceive('failed');

        $command = Mockery::mock(Command::class);
        $command->shouldReceive('info')->once()->with('Processed page.scraped for job job-message-process.');

        $bus = Mockery::mock(PipelineEventBus::class);
        $bus->shouldNotReceive('publishRetry');
        $bus->shouldNotReceive('publishFailed');

        Log::shouldReceive('info')
            ->once()
            ->with('pipeline.event', Mockery::on(function (array $context): bool {
                $this->assertSame('consume', $context['action']);
                $this->assertSame(PipelineEvent::PAGE_SCRAPED, $context['event_type']);
                $this->assertSame('task-message-process', $context['task_id']);
                $this->assertSame('job-message-process', $context['job_id']);

                return true;
            }));

        $exitCode = (new PipelineEventMessageProcessor(new PipelineEventDecoder, $bus, app(PipelineEventLogger::class)))
            ->process($command, $message, $handler);

        $this->assertSame(PipelineExitCode::SUCCESS, $exitCode);
    }

    public function test_it_publishes_retry_when_handler_fails(): void
    {
        config()->set('communication.rabbitmq.pipeline_events.max_retries', 3);

        $event = PipelineEvent::normalize(PipelineEvent::PAGE_SCRAPED, [
            'task_id' => 'task-message-retry',
            'job_id' => 'job-message-retry',
            'retry_count' => 1,
            'max_retries' => 3,
        ]);
        $error = new \RuntimeException('Handler failed.');

        $message = Mockery::mock(AMQPMessage::class);
        $message->shouldReceive('getBody')->once()->andReturn(json_encode($event, JSON_THROW_ON_ERROR));
        $message->shouldReceive('ack')->once();

        $handler = Mockery::mock(PipelineEventHandler::class);
        $handler->shouldReceive('eventTypes')->once()->andReturn([PipelineEvent::PAGE_SCRAPED]);
        $handler->shouldReceive('handle')->once()->andThrow($error);
        $handler->shouldReceive('failed')
            ->once()
            ->with(Mockery::type('array'), $error, 1, 3);

        $command = Mockery::mock(Command::class);
        $command->shouldReceive('warn')->once()->with('Retry published for page.scraped job job-message-retry.');

        $bus = Mockery::mock(PipelineEventBus::class);
        $bus->shouldReceive('publishRetry')
            ->once()
            ->with(Mockery::type('array'), $error)
            ->andReturn(array_merge($event, ['retry_count' => 2]));
        $bus->shouldNotReceive('publishFailed');

        Log::shouldReceive('info')
            ->once()
            ->with('pipeline.event', Mockery::on(fn (array $context): bool => $context['action'] === 'consume'));
        Log::shouldReceive('warning')
            ->once()
            ->with('Pipeline event worker failed', Mockery::on(function (array $context) use ($error): bool {
                $this->assertSame(PipelineEvent::PAGE_SCRAPED, $context['event_type']);
                $this->assertSame('task-message-retry', $context['task_id']);
                $this->assertSame('job-message-retry', $context['job_id']);
                $this->assertSame(1, $context['retry_count']);
                $this->assertSame(3, $context['max_retries']);
                $this->assertSame($error, $context['exception']);

                return true;
            }));

        $exitCode = (new PipelineEventMessageProcessor(new PipelineEventDecoder, $bus, app(PipelineEventLogger::class)))
            ->process($command, $message, $handler);

        $this->assertSame(PipelineExitCode::RUNTIME_FAILURE, $exitCode);
    }
}
