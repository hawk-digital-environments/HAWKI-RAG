<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Services\Pipeline\Tasks\PipelineTaskCounterService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ManagedPipelineResponseMapper
{
    public function __construct(private PipelineTaskCounterService $counters) {}

    /** Compatibility projection only; no Laravel task/source/job is persisted. */
    public function map(array $body): array
    {
        $document = $body['document'];
        $pipeline = $body['pipeline'];
        $startedAt = $document['created_at'] ?? null;
        $completed = ($document['status'] ?? null) === 'indexed';
        $status = $completed ? 'completed' : 'running';
        $counters = array_merge($this->counters->defaults(), ['jobs_total' => 1, 'jobs_active' => $completed ? 0 : 1, 'jobs_running' => $completed ? 0 : 1, 'jobs_completed' => $completed ? 1 : 0, 'ingested' => $completed ? 1 : 0, 'ingest_jobs' => 1]);
        $job = [
            ...$pipeline, 'job_type' => 'ingest', 'status' => $status, 'index_status' => $completed ? 'ready' : 'running',
            'source_url' => 'upload://'.($document['original_filename'] ?? $document['display_name'] ?? ''),
            'content_hash' => $document['source_checksum_sha256'] ?? null, 'started_at' => $startedAt,
            'finished_at' => null, 'error_message' => null,
        ];
        $task = [
            'task_id' => $pipeline['task_id'], 'dataset_id' => $document['dataset_id'], 'status' => $completed ? 'completed' : 'processing',
            'started_at' => $startedAt, 'finished_at' => null, 'updated_at' => $document['updated_at'] ?? $startedAt,
            'active_jobs' => $completed ? 0 : 1, 'counters' => $counters,
            'metadata' => ['request' => ['mode' => 'uploaded_file_convert_ingest']],
            'managed_documents' => [$document], 'managed_document_count' => 1,
            'sources' => [['source_id' => $pipeline['source_id'], 'source_url' => $job['source_url']]],
            'jobs' => [$job],
            'stages' => [
                'scrape' => ['status' => 'n/a', 'message' => 'Mode not available for uploaded files.', 'counts' => []],
                'convert' => ['status' => $completed ? 'completed' : 'processing', 'message' => $completed ? 'Conversion finished.' : 'Converting uploaded file.', 'started_at' => $startedAt, 'completed_at' => $document['indexed_at'] ?? null, 'counts' => [], 'errors' => []],
                'ingest' => ['status' => $completed ? 'completed' : 'queued', 'message' => $completed ? 'Ingestion finished.' : 'Waiting for converter to finish.', 'started_at' => null, 'completed_at' => $document['indexed_at'] ?? null, 'counts' => [], 'errors' => []],
            ],
        ];

        return [
            'success' => true, ...$pipeline, 'dataset_id' => $document['dataset_id'],
            'document_id' => $document['document_id'], 'document' => $document, 'task' => $task,
        ];
    }
}
