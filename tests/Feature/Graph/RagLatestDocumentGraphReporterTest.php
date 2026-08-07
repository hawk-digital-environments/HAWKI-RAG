<?php

declare(strict_types=1);

namespace Tests\Feature\Graph;

use App\Models\ManagedDocument;
use App\Models\ManagedDocumentOutput;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Models\PipelineWorkerEventRecord;
use App\Models\RagIngestionArtifact;
use App\Services\Rag\RagLatestDocumentGraphReporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RagLatestDocumentGraphReporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_the_latest_managed_document_from_ingestion_artifacts(): void
    {
        $task = PipelineTask::query()->create([
            'task_id' => 'task-report-1',
            'dataset_id' => 'report-dataset',
            'status' => PipelineTask::STATUS_COMPLETED,
            'metadata' => [],
        ]);
        $job = PipelineJob::query()->create([
            'job_id' => 'job-report-1',
            'task_id' => $task->task_id,
            'source_id' => 'source-report-1',
            'job_type' => PipelineJob::TYPE_INGEST,
            'status' => PipelineJob::STATUS_COMPLETED,
            'metadata' => [],
        ]);
        $event = PipelineWorkerEventRecord::query()->create([
            'pipeline_job_id' => $job->id,
            'event_id' => 'event-report-1',
            'job_id' => $job->job_id,
            'task_id' => $task->task_id,
            'source_id' => 'source-report-1',
            'workflow_id' => 'workflow-report-1',
            'run_id' => 'run-report-1',
            'activity_id' => 'mark_source_ready',
            'attempt' => 1,
            'event_type' => 'pipeline.stage.status',
            'producer' => 'indexer',
            'stage' => 'ingest',
            'phase' => 'mark_source_ready',
            'status' => 'completed',
            'payload_hash' => hash('sha256', 'event-report-1'),
            'payload' => [],
            'occurred_at' => Carbon::parse('2026-07-13T10:01:00Z'),
            'processed_at' => Carbon::parse('2026-07-13T10:01:01Z'),
        ]);

        $managedDocument = ManagedDocument::query()->create([
            'document_id' => 'adoc_report_1',
            'dataset_id' => 'report-dataset',
            'display_name' => 'Managed Report',
            'source_type' => 'upload',
            'source_url' => 'upload://report.pdf',
            'graph_enabled' => true,
            'status' => ManagedDocument::STATUS_INDEXED,
            'latest_source_id' => 'source-report-1',
            'latest_task_id' => $task->task_id,
            'latest_job_id' => $job->job_id,
            'indexed_at' => Carbon::parse('2026-07-13T10:00:00Z'),
        ]);
        $managedDocument->forceFill([
            'created_at' => Carbon::parse('2026-07-13T10:00:00Z'),
            'updated_at' => Carbon::parse('2026-07-13T10:01:00Z'),
        ])->save();

        ManagedDocumentOutput::query()->create([
            'document_id' => 'adoc_report_1',
            'bridge_document_id' => 'doc-report-1',
            'qdrant_collection' => 'hawki_report_dataset',
            'neo4j_namespace' => 'hawki_report_dataset',
            'chunk_count' => 12,
            'status' => 'indexed',
            'active' => true,
            'indexed_at' => Carbon::parse('2026-07-13T10:00:00Z'),
        ]);

        RagIngestionArtifact::query()->create([
            'pipeline_job_id' => $job->id,
            'pipeline_worker_event_id' => $event->id,
            'job_id' => $job->job_id,
            'task_id' => $task->task_id,
            'source_id' => 'source-report-1',
            'dataset_id' => 'report-dataset',
            'workflow_id' => 'workflow-report-1',
            'run_id' => 'run-report-1',
            'summary' => [
                'graph' => ['enabled' => true],
                'qdrant_preview' => ['planned_points' => 144],
            ],
            'graph_preview' => [
                'total_triplets' => 9,
                'docs_with_triplets' => 1,
            ],
            'occurred_at' => Carbon::parse('2026-07-13T10:01:00Z'),
        ]);

        $report = app(RagLatestDocumentGraphReporter::class)->report();

        $this->assertSame('adoc_report_1', $report['document_id']);
        $this->assertSame('doc-report-1', $report['external_id']);
        $this->assertSame('report-dataset', $report['dataset_id']);
        $this->assertSame('hawki_report_dataset', $report['collection']);
        $this->assertSame('Managed Report', $report['title']);
        $this->assertSame('upload://report.pdf', $report['source_url']);
        $this->assertSame('2026-07-13T10:01:00+00:00', $report['updated_at']);
        $this->assertSame(144, $report['qdrant_points']);
        $this->assertTrue($report['graph_enabled']);
        $this->assertSame(9, $report['graph_triplets']);
        $this->assertSame(1, $report['docs_with_triplets']);
    }

    public function test_it_returns_null_when_no_managed_document_exists(): void
    {
        $this->assertNull(app(RagLatestDocumentGraphReporter::class)->report());
    }
}
