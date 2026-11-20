<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Values\PipelineStage;
use App\Services\Pipeline\Values\PipelineStageStatus;
use App\Services\Pipeline\Values\PipelineWorker;
use App\Services\Pipeline\Values\PipelineWorkerEvent;
use PHPUnit\Framework\TestCase;

final class PipelineWorkerEventTest extends TestCase
{
    public function test_it_builds_a_typed_event_from_validated_input(): void
    {
        $data = [
            'schema_version' => 1,
            'event_id' => 'evt_typed',
            'event_type' => PipelineWorkerEvent::EVENT_TYPE,
            'producer' => 'indexer',
            'timestamp' => '2026-08-03T12:00:00.123456Z',
            'workflow_id' => 'workflow-1',
            'run_id' => 'run-1',
            'activity_id' => 'ingest_markdown_files',
            'attempt' => 2,
            'job_id' => 'job-1',
            'task_id' => 'task-1',
            'source_id' => 'source-1',
            'stage' => 'ingest',
            'phase' => 'ingest_markdown_files',
            'status' => 'completed',
            'counts' => ['total' => '2', 'processed' => '2', 'failed' => 0, 'skipped' => 0],
            'metrics' => ['chunks_indexed' => 8],
            'artifacts' => [[
                'uri' => 's3://artifacts/source-1/document.md',
                'sha256' => str_repeat('a', 64),
                'size_bytes' => 42,
                'media_type' => 'text/markdown',
            ]],
            'manifest' => ['uri' => 's3://artifacts/source-1/manifest.json'],
            'errors' => [[
                'code' => 'provider_slow',
                'message' => 'Provider recovered after retry.',
                'retryable' => true,
            ]],
            'warnings' => ['slow provider'],
            'error_details' => null,
            'document_version' => 'abcdef12',
        ];
        $body = json_encode($data, JSON_THROW_ON_ERROR);

        $event = PipelineWorkerEvent::fromValidated($data, $body);

        $this->assertSame(PipelineWorker::Indexer, $event->producer);
        $this->assertSame(PipelineStage::Ingest, $event->stage);
        $this->assertSame(PipelineStageStatus::Completed, $event->status);
        $this->assertSame(2, $event->counts['processed']);
        $this->assertSame(8, $event->metrics['chunks_indexed']);
        $this->assertSame('123456', $event->occurredAtInstant->format('u'));
        $this->assertSame('text/markdown', $event->artifacts[0]['media_type']);
        $this->assertSame('provider_slow', $event->errors[0]['code']);
        $this->assertSame(hash('sha256', $body), $event->payloadHash);
        $this->assertTrue($event->status->isTerminal());
    }

    public function test_workers_define_their_owned_stage_and_activities(): void
    {
        $this->assertSame(PipelineStage::Scrape, PipelineWorker::Scraper->stage());
        $this->assertSame(PipelineStage::Convert, PipelineWorker::Converter->stage());
        $this->assertSame(PipelineStage::Ingest, PipelineWorker::Indexer->stage());
        $this->assertTrue(PipelineWorker::Indexer->acceptsActivity('mark_source_ready'));
        $this->assertFalse(PipelineWorker::Scraper->acceptsActivity('ingest_markdown_files'));
    }
}
