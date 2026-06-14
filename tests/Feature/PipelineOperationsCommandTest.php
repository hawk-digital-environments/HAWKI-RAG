<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PipelineOperationsCommandTest extends TestCase
{
    public function test_pipeline_workers_prints_temporal_start_commands_and_task_queues(): void
    {
        $exitCode = Artisan::call('pipeline:workers');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('HAWKI RAG Temporal ingestion workers', $output);
        $this->assertStringContainsString('docker compose up -d postgres temporal hawki_rag_app', $output);
        $this->assertStringContainsString('docker compose --profile devtools up -d temporal-ui', $output);
        $this->assertStringContainsString('hawki-rag-temporal-workflow-worker', $output);
        $this->assertStringContainsString('hawki-rag-temporal-scraper-worker', $output);
        $this->assertStringContainsString('hawki-rag-temporal-converter-worker', $output);
        $this->assertStringContainsString('hawki-rag-temporal-ingestion-worker', $output);
        $this->assertStringContainsString('rag-workflow-task-queue', $output);
        $this->assertStringContainsString('rag-scraper-task-queue', $output);
        $this->assertStringContainsString('rag-converter-task-queue', $output);
        $this->assertStringContainsString('rag-ingestion-task-queue', $output);
    }
}
