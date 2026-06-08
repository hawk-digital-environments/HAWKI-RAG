<?php
declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\EventHandlers\PipelineEventHandler;
use App\Services\Pipeline\PipelineEventBus;
use App\Services\Pipeline\PipelineEventWorker;
use App\Services\Rag\RagRabbitMQ;
use App\Support\PipelineExitCode;
use Illuminate\Console\Command;
use Mockery;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Tests\TestCase;

class PipelineEventWorkerTest extends TestCase
{
    public function test_once_worker_returns_partial_success_when_no_message_arrives_before_timeout(): void
    {
        $bus = Mockery::mock(PipelineEventBus::class);
        $bus->shouldReceive('declareWorkerTopology')
            ->once()
            ->with('scraper')
            ->andReturn([
                'queue' => 'pipeline_scraper_events',
                'consumer_tag' => 'hawki-rag-scraper-events',
            ]);

        $channel = Mockery::mock(AMQPChannel::class);
        $channel->shouldReceive('basic_qos')->once()->with(0, 1, false);
        $channel->shouldReceive('basic_consume')
            ->once()
            ->with('pipeline_scraper_events', 'hawki-rag-scraper-events', false, false, false, false, Mockery::type('callable'));
        $channel->shouldReceive('is_consuming')->once()->andReturnTrue();
        $channel->shouldReceive('wait')
            ->once()
            ->with(null, false, 1)
            ->andThrow(new AMQPTimeoutException('No message.'));

        $rabbit = Mockery::mock(RagRabbitMQ::class);
        $rabbit->shouldReceive('channel')->once()->andReturn($channel);
        $rabbit->shouldReceive('close')->once();

        $command = Mockery::mock(Command::class);
        $command->shouldReceive('option')->with('once')->andReturnTrue();
        $command->shouldReceive('option')->with('timeout')->andReturn(1);
        $command->shouldReceive('info')->once()->with('Pipeline scraper event worker listening on pipeline_scraper_events.');
        $command->shouldReceive('line')->once()->with('No message received before timeout.');

        $handler = Mockery::mock(PipelineEventHandler::class);
        $handler->shouldNotReceive('handle');

        $exitCode = (new PipelineEventWorker($rabbit, $bus))->run($command, 'scraper', $handler);

        $this->assertSame(PipelineExitCode::PARTIAL_SUCCESS, $exitCode);
    }
}
