<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\DirectIngest\DirectIngestProcessLauncher;
use App\Services\DirectIngest\Values\DirectIngestStatusPaths;
use Tests\TestCase;

class DirectIngestProcessLauncherTest extends TestCase
{
    public function test_it_builds_detached_shell_command_with_exit_markers_and_logs(): void
    {
        $paths = new DirectIngestStatusPaths('/tmp/status.json', '/tmp/cache.log', '/tmp/full.log');

        $command = app(DirectIngestProcessLauncher::class)->commandLine([
            'python3',
            '-u',
            '/app/ingest.py',
            '--root',
            '/app/shared/crawl',
        ], [
            'graph_model' => 'llama3.1',
        ], $paths);

        $this->assertStringContainsString('GRAPH_OLLAMA_RAG_MODEL', $command);
        $this->assertStringContainsString('INGEST_EXIT_CODE', $command);
        $this->assertStringContainsString('INGEST_DONE', $command);
        $this->assertStringContainsString('INGEST_FAILED', $command);
        $this->assertStringContainsString('tee -a', $command);
        $this->assertStringContainsString('/tmp/full.log', $command);
        $this->assertStringContainsString('/tmp/cache.log', $command);
    }
}
