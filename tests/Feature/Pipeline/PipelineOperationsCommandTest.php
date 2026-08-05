<?php

declare(strict_types=1);

namespace Tests\Feature\Pipeline;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PipelineOperationsCommandTest extends TestCase
{
    public function test_only_current_pipeline_commands_are_registered(): void
    {
        $commands = array_keys(Artisan::all());

        foreach (['pipeline:health', 'pipeline:start-task', 'pipeline:workers'] as $command) {
            $this->assertContains($command, $commands);
        }

        foreach ([
            'pipeline:architecture',
            'pipeline:demo',
            'pipeline:retry-failed-jobs',
            'pipeline:show-task',
        ] as $command) {
            $this->assertNotContains($command, $commands);
        }
    }

    public function test_pipeline_workers_prints_temporal_start_commands_and_task_queues(): void
    {
        $exitCode = Artisan::call('pipeline:workers');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('HAWKI RAG Temporal ingestion workers', $output);
        $this->assertStringContainsString('make up-core', $output);
        $this->assertStringContainsString('make up-core-local', $output);
        $this->assertStringNotContainsString('docker compose up', $output);
        $this->assertStringNotContainsString('temporal-ui', $output);
        $this->assertStringContainsString('hawki-rag-temporal-workflow-worker', $output);
        $this->assertStringContainsString('hawki-rag-temporal-scraper-worker', $output);
        $this->assertStringContainsString('hawki-rag-temporal-converter-worker', $output);
        $this->assertStringContainsString('hawki-rag-indexer-worker', $output);
        $this->assertStringNotContainsString('hawki-rag-temporal-ingestion-worker', $output);
        $this->assertStringContainsString('rag-workflow-task-queue', $output);
        $this->assertStringContainsString('rag-scraper-task-queue', $output);
        $this->assertStringContainsString('rag-converter-task-queue', $output);
        $this->assertStringContainsString('rag-ingestion-task-queue', $output);
    }
}
