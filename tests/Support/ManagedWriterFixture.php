<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Dataset;
use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Authorization\DatasetIngestionAuthorizationService;

trait ManagedWriterFixture
{
    private function seedWriter(): void
    {
        config()->set('temporal.callbacks.secret', 'writer-test-secret');
        $user = $this->actingAsApiUser();
        $dataset = Dataset::query()->create(['dataset_id' => 'writer-test', 'name' => 'writer-test', 'status' => 'active', 'qdrant_collection' => 'current_collection', 'neo4j_namespace' => 'current_namespace']);
        app(DatasetIngestionAuthorizationService::class)->grantAccess($user, $dataset);
        ManagedDocument::query()->create(['document_id' => 'adoc_writer_test', 'dataset_id' => 'writer-test', 'source_type' => 'upload', 'status' => 'failed', 'graph_enabled' => true, 'latest_source_id' => 'source-writer']);
        PipelineTask::query()->create(['task_id' => 'writer-task', 'dataset_id' => 'writer-test', 'status' => 'running']);
        IngestionSource::query()->create(['source_id' => 'source-writer', 'dataset_id' => 'writer-test', 'task_id' => 'writer-task', 'source_url' => 'upload://writer.pdf', 'index_status' => 'failed', 'temporal_workflow_id' => 'writer-workflow', 'metadata' => [
            'request' => ['metadata' => ['managed_document_id' => 'adoc_writer_test']],
            'temporal' => ['run_id' => 'writer-run'],
            'managed_dispatch' => ['attempted' => true, 'input' => ['ingestion' => ['collection' => 'historic_collection', 'neo4j_namespace' => 'historic_namespace', 'graph' => true]]],
        ]]);
        PipelineJob::query()->create(['job_id' => 'writer-job', 'task_id' => 'writer-task', 'source_id' => 'source-writer', 'job_type' => 'ingest', 'status' => 'failed', 'temporal_workflow_id' => 'writer-workflow', 'temporal_run_id' => 'writer-run']);
    }

    private function writerEvent(string $action = 'acquire', array $targets = []): array
    {
        return ['schema_version' => 1, 'event_id' => 'writer-event', 'action' => $action, 'source_id' => 'source-writer', 'job_id' => 'writer-job', 'workflow_id' => 'writer-workflow', 'run_id' => 'writer-run', 'activity_id' => '3', 'attempt' => 1, 'targets' => $targets];
    }

    private function target(string $path = 'one.md'): array
    {
        return ['doc_id' => 'doc_'.substr(hash('sha256', 'source-writer|'.$path), 0, 40), 'relative_path' => $path];
    }

    private function signedWriter(array $event)
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);
        $time = (string) time();

        return $this->call('POST', '/api/internal/pipeline/managed-writers', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_HAWKI_TIMESTAMP' => $time,
            'HTTP_X_HAWKI_SIGNATURE' => 'v1='.hash_hmac('sha256', $time.'.'.$body, 'writer-test-secret')], $body);
    }
}
