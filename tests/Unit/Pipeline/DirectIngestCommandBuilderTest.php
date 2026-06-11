<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\DirectIngest\DirectIngestCommandBuilder;
use Tests\TestCase;

class DirectIngestCommandBuilderTest extends TestCase
{
    public function test_it_builds_python_ingest_command_from_direct_ingest_payload(): void
    {
        config()->set('config.ingest_timeout', 6000);

        $command = app(DirectIngestCommandBuilder::class)->build([
            'collection' => 'hawki_docs',
            'provider' => 'ollama',
            'embedding_model' => 'nomic-embed-text',
            'graph' => true,
            'graph_only' => false,
            'graph_engine' => 'neo4j',
            'graph_model' => 'llama3.2:3b',
            'neo4j_database' => 'neo4j',
            'chunk_chars' => 1200,
            'chunk_overlap' => 120,
            'batch' => 10,
            'timeout' => 300,
            'resume_mode' => 'start',
        ], '/app/python_rag/application/cli/commands/ingest_crawled.py', '/app/shared/crawl', 'http://bridge:8000', '/tmp/summary.json');

        $this->assertSame('python3', $command[0]);
        $this->assertContains('--root', $command);
        $this->assertContains('/app/shared/crawl', $command);
        $this->assertContains('--collection', $command);
        $this->assertContains('hawki_docs', $command);
        $this->assertContains('--graph', $command);
        $this->assertContains('--graph-model', $command);
        $this->assertContains('llama3.2:3b', $command);
        $this->assertContains('--start', $command);
        $this->assertContains('--summary-file', $command);
        $this->assertContains('/tmp/summary.json', $command);
    }

    public function test_it_defaults_to_resume_and_configured_timeout(): void
    {
        config()->set('config.ingest_timeout', 42);

        $command = app(DirectIngestCommandBuilder::class)->build(
            [],
            '/app/ingest.py',
            '/app/shared/crawl',
            'http://bridge:8000',
            '/tmp/summary.json',
        );

        $this->assertContains('--resume', $command);
        $this->assertContains('--timeout', $command);
        $this->assertContains('42', $command);
        $this->assertContains(basename('/app/shared/crawl'), $command);
    }
}
