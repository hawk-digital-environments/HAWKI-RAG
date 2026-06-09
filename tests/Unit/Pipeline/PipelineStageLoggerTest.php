<?php
declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\PipelineStageLogger;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class PipelineStageLoggerTest extends TestCase
{
    public function test_it_logs_stage_context_to_the_communication_channel(): void
    {
        $exception = new RuntimeException('Scrape failed.');
        $channel = Mockery::mock();

        Log::shouldReceive('channel')
            ->once()
            ->with(PipelineStageLogger::CHANNEL)
            ->andReturn($channel);

        $channel->shouldReceive('log')
            ->once()
            ->with('error', PipelineStageLogger::EVENT, Mockery::on(function (array $context) use ($exception): bool {
                $this->assertSame(PipelineStageLogger::EVENT, $context['event']);
                $this->assertSame('scrape', $context['stage']);
                $this->assertSame('failed', $context['status']);
                $this->assertSame('job-stage-log', $context['job_id']);
                $this->assertSame('doc-stage-log', $context['doc_id']);
                $this->assertSame(RuntimeException::class, $context['error_type']);
                $this->assertSame('Scrape failed.', $context['error_message']);
                $this->assertArrayNotHasKey('exception', $context);

                return true;
            }));

        app(PipelineStageLogger::class)->failed('scrape', [
            'job_id' => 'job-stage-log',
            'doc_id' => 'doc-stage-log',
            'exception' => $exception,
        ]);
    }
}
