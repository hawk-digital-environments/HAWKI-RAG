<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\DocumentDeduplicationState;
use App\Services\Pipeline\Values\DocumentDeduplicationBackfillCandidate;
use App\Services\Pipeline\Values\HistoricalManagedDocument;
use App\Services\Pipeline\Values\HistoricalManagedDocumentOutput;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

#[Singleton]
readonly class DocumentDeduplicationBackfillRepository
{
    public function __construct(
        private DatabaseManager $database,
    ) {}

    /**
     * @return \Generator<int, HistoricalManagedDocument>
     */
    public function historicalManagedDocuments(
        ?string $datasetId,
        ?string $scopeKey,
        int $chunkSize,
    ): \Generator {
        $query = $this->database->connection()
            ->table('managed_documents as documents')
            ->leftJoin('datasets', 'datasets.dataset_id', '=', 'documents.dataset_id')
            ->leftJoin('pipeline_jobs as jobs', 'jobs.job_id', '=', 'documents.latest_job_id')
            ->leftJoin('ingestion_sources as sources', 'sources.source_id', '=', 'documents.latest_source_id')
            ->leftJoin('pipeline_tasks as tasks', 'tasks.task_id', '=', 'documents.latest_task_id')
            ->leftJoin('managed_document_outputs as outputs', function (JoinClause $join): void {
                $join->on('outputs.document_id', '=', 'documents.document_id')
                    ->where('outputs.active', '=', true)
                    ->whereNull('outputs.deleted_at');
            })
            ->select([
                'documents.document_id',
                'documents.dataset_id',
                'datasets.qdrant_collection as dataset_qdrant_collection',
                'documents.source_type',
                'documents.source_checksum_sha256',
                'documents.status as document_status',
                'documents.latest_source_id',
                'documents.latest_task_id',
                'documents.latest_job_id',
                'documents.indexed_at as document_indexed_at',
                'documents.deleted_at as document_deleted_at',
                'jobs.content_hash as job_source_hash_claim',
                'jobs.task_id as job_task_id',
                'jobs.source_id as job_source_id',
                'sources.metadata as source_metadata',
                'sources.task_id as source_task_id',
                'sources.dataset_id as source_dataset_id',
                'tasks.metadata as task_metadata',
                'tasks.dataset_id as task_dataset_id',
                'outputs.id as output_id',
                'outputs.bridge_document_id',
                'outputs.qdrant_collection',
                'outputs.source_id as output_source_id',
                'outputs.task_id as output_task_id',
                'outputs.job_id as output_job_id',
                'outputs.content_hash as output_content_hash',
                'outputs.chunk_count',
                'outputs.status as output_status',
                'outputs.indexed_at as output_indexed_at',
            ])
            ->orderBy('documents.document_id')
            ->orderBy('outputs.id');

        if ($datasetId !== null) {
            $query->where('documents.dataset_id', $datasetId);
        }

        if ($scopeKey !== null) {
            $query->whereExists(function (Builder $scopedOutputs) use ($scopeKey): void {
                $scopedOutputs->selectRaw('1')
                    ->from('managed_document_outputs as scoped_outputs')
                    ->whereColumn('scoped_outputs.document_id', 'documents.document_id')
                    ->where('scoped_outputs.active', true)
                    ->whereNull('scoped_outputs.deleted_at')
                    ->where('scoped_outputs.qdrant_collection', $scopeKey);
            });
        }

        $currentDocumentId = null;
        $documentRow = null;
        $outputs = [];

        foreach ($query->lazy(max(1, $chunkSize)) as $row) {
            $rowDocumentId = (string) $row->document_id;
            if ($currentDocumentId !== null && $rowDocumentId !== $currentDocumentId) {
                yield $this->historicalDocument($documentRow, $outputs);
                $outputs = [];
            }

            $currentDocumentId = $rowDocumentId;
            $documentRow = $row;

            if ($row->output_id !== null) {
                $outputs[] = new HistoricalManagedDocumentOutput(
                    id: (int) $row->output_id,
                    bridgeDocumentId: $this->stringValue($row->bridge_document_id),
                    qdrantCollection: $this->stringValue($row->qdrant_collection),
                    sourceId: $this->stringValue($row->output_source_id),
                    taskId: $this->stringValue($row->output_task_id),
                    jobId: $this->stringValue($row->output_job_id),
                    contentHash: $this->normalizedHash($row->output_content_hash),
                    chunkCount: max(0, (int) $row->chunk_count),
                    status: $this->stringValue($row->output_status),
                    indexedAt: $this->stringValue($row->output_indexed_at),
                );
            }
        }

        if ($documentRow !== null) {
            yield $this->historicalDocument($documentRow, $outputs);
        }
    }

    /**
     * @param  list<DocumentDeduplicationBackfillCandidate>  $candidates
     * @return array<string, array{status:string,completed_content_hash:?string}>
     */
    public function statesFor(array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $documentIds = array_values(array_unique(array_map(
            static fn (DocumentDeduplicationBackfillCandidate $candidate): string => $candidate->documentId,
            $candidates,
        )));
        $scopeKeys = array_values(array_unique(array_map(
            static fn (DocumentDeduplicationBackfillCandidate $candidate): string => $candidate->scopeKey,
            $candidates,
        )));

        $states = [];
        foreach ($this->database->connection()->table('document_deduplication_states')
            ->whereIn('document_id', $documentIds)
            ->whereIn('scope_key', $scopeKeys)
            ->get(['scope_key', 'document_id', 'status', 'completed_content_hash']) as $row) {
            $states[$this->stateKey((string) $row->scope_key, (string) $row->document_id)] = [
                'status' => (string) $row->status,
                'completed_content_hash' => $this->normalizedHash($row->completed_content_hash),
            ];
        }

        return $states;
    }

    public function seedIfAbsent(
        DocumentDeduplicationBackfillCandidate $candidate,
        \DateTimeImmutable $checkedAt,
    ): bool {
        $inserted = $this->database->connection()
            ->table('document_deduplication_states')
            ->insertOrIgnore([
                'scope_key' => $candidate->scopeKey,
                'document_id' => $candidate->documentId,
                'completed_content_hash' => $candidate->contentHash,
                'pending_content_hash' => null,
                'status' => DocumentDeduplicationState::STATUS_COMPLETED,
                'decision' => null,
                'claim_token' => null,
                'lease_expires_at' => null,
                'completed_source_id' => $candidate->sourceId,
                'pending_source_id' => null,
                'task_id' => $candidate->taskId,
                'job_id' => $candidate->jobId,
                'checked_at' => $checkedAt,
                'completed_at' => $candidate->completedAt,
                'metadata' => json_encode([
                    'backfill' => [
                        'version' => 1,
                        'strategy' => 'validated_managed_upload',
                        'source_hash_field' => 'managed_documents.source_checksum_sha256',
                        'server_source_hash_evidence' => $candidate->sourceHashEvidence,
                        'active_indexed_outputs' => $candidate->outputCount(),
                        'latest_run_matches_outputs' => true,
                        'qdrant_outputs_verified' => true,
                    ],
                ], JSON_THROW_ON_ERROR),
                'created_at' => $checkedAt,
                'updated_at' => $checkedAt,
            ]);

        return $inserted === 1;
    }

    public function unverifiedRegistryRecordCount(?string $datasetId, ?string $scopeKey): int
    {
        $resolvedScope = $scopeKey;
        if ($resolvedScope === null && $datasetId !== null) {
            $resolvedScope = $this->stringValue(
                $this->database->connection()->table('datasets')
                    ->where('dataset_id', $datasetId)
                    ->value('qdrant_collection'),
            );

            if ($resolvedScope === null) {
                return 0;
            }
        }

        $query = $this->database->connection()->table('ingested_pages as pages')
            ->where('pages.status', 'completed')
            ->whereNotExists(function (Builder $managedOutput): void {
                $managedOutput->selectRaw('1')
                    ->from('managed_document_outputs as registry_outputs')
                    ->whereColumn('registry_outputs.bridge_document_id', 'pages.doc_id')
                    ->whereColumn('registry_outputs.qdrant_collection', 'pages.collection');
            })
            ->whereNotExists(function (Builder $deduplicationState): void {
                $deduplicationState->selectRaw('1')
                    ->from('document_deduplication_states as registry_states')
                    ->whereColumn('registry_states.document_id', 'pages.doc_id')
                    ->whereColumn('registry_states.scope_key', 'pages.collection');
            });

        if ($resolvedScope !== null) {
            $query->where(function (Builder $scopeQuery) use ($resolvedScope): void {
                $scopeQuery->where('pages.collection', $resolvedScope)
                    ->orWhere('pages.qdrant_collection', $resolvedScope);
            });
        }

        return $query->count();
    }

    /**
     * @param  list<HistoricalManagedDocumentOutput>  $outputs
     */
    private function historicalDocument(object $row, array $outputs): HistoricalManagedDocument
    {
        return new HistoricalManagedDocument(
            documentId: (string) $row->document_id,
            datasetId: $this->stringValue($row->dataset_id),
            datasetQdrantCollection: $this->stringValue($row->dataset_qdrant_collection),
            sourceType: $this->stringValue($row->source_type),
            sourceChecksum: $this->normalizedHash($row->source_checksum_sha256),
            status: $this->stringValue($row->document_status),
            latestSourceId: $this->stringValue($row->latest_source_id),
            latestTaskId: $this->stringValue($row->latest_task_id),
            latestJobId: $this->stringValue($row->latest_job_id),
            indexedAt: $this->stringValue($row->document_indexed_at),
            deletedAt: $this->stringValue($row->document_deleted_at),
            jobSourceHashClaim: $this->stringValue($row->job_source_hash_claim),
            jobTaskId: $this->stringValue($row->job_task_id),
            jobSourceId: $this->stringValue($row->job_source_id),
            sourceUploadHashClaim: $this->metadataUploadHash($row->source_metadata),
            sourceTaskId: $this->stringValue($row->source_task_id),
            sourceDatasetId: $this->stringValue($row->source_dataset_id),
            taskUploadHashClaim: $this->metadataUploadHash($row->task_metadata),
            taskDatasetId: $this->stringValue($row->task_dataset_id),
            outputs: $outputs,
        );
    }

    private function stateKey(string $scopeKey, string $documentId): string
    {
        return $scopeKey."\0".$documentId;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function normalizedHash(mixed $value): ?string
    {
        $hash = strtolower((string) ($this->stringValue($value) ?? ''));

        return preg_match('/\A[a-f0-9]{64}\z/', $hash) === 1 ? $hash : null;
    }

    private function metadataUploadHash(mixed $value): ?string
    {
        if (is_string($value)) {
            try {
                $value = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return null;
            }
        }

        if (! is_array($value)) {
            return null;
        }

        $upload = $value['upload'] ?? null;

        return is_array($upload) ? $this->stringValue($upload['content_hash'] ?? null) : null;
    }
}
