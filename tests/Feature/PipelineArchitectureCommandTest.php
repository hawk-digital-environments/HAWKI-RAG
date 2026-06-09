<?php
declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PipelineArchitectureCommandTest extends TestCase
{
    public function test_pipeline_architecture_command_prints_contracts_topology_and_failures(): void
    {
        $exitCode = Artisan::call('pipeline:architecture');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Pipeline event contracts', $output);
        $this->assertStringContainsString('scrape.requested', $output);
        $this->assertStringContainsString('RabbitMQ topology', $output);
        $this->assertStringContainsString('pipeline.events', $output);
        $this->assertStringContainsString('Failure modes', $output);
        $this->assertStringContainsString('retry_limit_exhausted', $output);
        $this->assertStringContainsString('Handler responsibilities', $output);
        $this->assertStringContainsString('ScrapeMonitorEventHandler', $output);
        $this->assertStringContainsString('Persistence map', $output);
        $this->assertStringContainsString('pipeline_jobs', $output);
        $this->assertStringContainsString('Mental model', $output);
    }
}
