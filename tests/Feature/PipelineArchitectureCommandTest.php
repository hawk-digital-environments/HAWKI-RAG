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
        $this->assertStringContainsString('Temporal RAG ingestion architecture', $output);
        $this->assertStringContainsString('IngestSourceWorkflow', $output);
        $this->assertStringContainsString('scrape_source', $output);
        $this->assertStringContainsString('inspect_and_convert_files', $output);
        $this->assertStringContainsString('ingest_markdown_files', $output);
        $this->assertStringContainsString('Persistence map', $output);
        $this->assertStringContainsString('PostgreSQL Temporal tables', $output);
        $this->assertStringContainsString('Scheduling', $output);
    }
}
