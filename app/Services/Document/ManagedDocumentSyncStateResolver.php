<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\Document;
use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use App\Models\RagIngestionArtifact;
use App\Services\Dataset\DatasetRepository;
use App\Services\Document\Repositories\ManagedIngestionMetadataRepository;
use App\Services\Document\Values\ManagedDocumentSyncState;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class ManagedDocumentSyncStateResolver
{
    public function __construct(
        private ManagedIngestionMetadataRepository $metadata,
        private DatasetRepository $datasets,
        private ClockInterface $clock = new Clock,
    ) {}

    public function resolve(ManagedDocument $document, IngestionSource $source): ManagedDocumentSyncState
    {
        $attributes = array_filter([
            'latest_document_version' => $this->stringValue($source->document_version) ?? $document->latest_document_version,
            'source_checksum_sha256' => $document->source_checksum_sha256 ?: $this->stringValue($source->content_hash),
        ], static fn (mixed $value): bool => $value !== null);

        if ($source->index_status === IngestionSource::STATUS_READY) {
            $attributes['status'] = ManagedDocument::STATUS_INDEXED;
            $attributes['indexed_at'] = $source->ready_at ?? Carbon::instance($this->clock->now());
            $attributes['last_error'] = null;

            return new ManagedDocumentSyncState($attributes, $this->resolvedOutputs($document, $source));
        }

        if ($source->index_status === IngestionSource::STATUS_FAILED || $source->index_status === IngestionSource::STATUS_CANCELLED) {
            $metadata = is_array($source->metadata) ? $source->metadata : [];
            $attributes['status'] = ManagedDocument::STATUS_FAILED;
            $attributes['last_error'] = $this->stringValue($metadata['error'] ?? null) ?? $document->last_error;

            return new ManagedDocumentSyncState($attributes, []);
        }

        $attributes['status'] = ManagedDocument::STATUS_PROCESSING;

        return new ManagedDocumentSyncState($attributes, []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolvedOutputs(ManagedDocument $managedDocument, IngestionSource $source): array
    {
        $sourceId = $source->source_id;
        $documents = $this->metadata->documentsForSource($sourceId);
        if ($documents->isEmpty()) {
            return $this->artifactOutputs($managedDocument, $source);
        }

        $chunkCounts = $this->metadata->chunkCountsForSource($sourceId);
        $outputs = [];

        foreach ($documents as $document) {
            $bridgeDocumentId = $this->bridgeDocumentId($document);
            if ($bridgeDocumentId === null) {
                continue;
            }

            $metadata = is_array($document->metadata_json) ? $document->metadata_json : [];
            $outputs[$bridgeDocumentId] = [
                'bridge_document_id' => $bridgeDocumentId,
                'qdrant_collection' => $this->stringValue($metadata['qdrant_collection'] ?? null) ?? $document->collection,
                'neo4j_namespace' => $this->stringValue($metadata['neo4j_namespace'] ?? null),
                'source_id' => $sourceId,
                'task_id' => $this->stringValue($metadata['task_id'] ?? null),
                'job_id' => $this->stringValue($metadata['job_id'] ?? null),
                'content_hash' => $this->stringValue($document->checksum_sha256),
                'chunk_count' => $this->chunkCount($chunkCounts, $metadata, $bridgeDocumentId),
                'status' => 'indexed',
                'indexed_at' => $document->updated_at ?? $document->created_at,
                'metadata_json' => $metadata,
            ];
        }

        return array_values($outputs);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function artifactOutputs(ManagedDocument $managedDocument, IngestionSource $source): array
    {
        $artifact = $this->metadata->latestArtifactForSource($source->source_id);
        if (! $artifact instanceof RagIngestionArtifact) {
            return [];
        }

        $summary = is_array($artifact->summary) ? $artifact->summary : [];
        $documents = is_array($summary['documents'] ?? null) ? $summary['documents'] : [];
        $chunkCounts = is_array($documents['chunks_per_doc'] ?? null) ? $documents['chunks_per_doc'] : [];
        $documentIds = is_array($documents['doc_ids'] ?? null) ? $documents['doc_ids'] : array_keys($chunkCounts);
        $dataset = $this->datasets->findByDatasetId($managedDocument->dataset_id);
        $qdrantCollection = $this->stringValue(data_get($summary, 'qdrant_preview.collection'))
            ?? $this->stringValue($dataset?->qdrant_collection);

        if ($qdrantCollection === null) {
            return [];
        }

        $outputs = [];
        foreach ($documentIds as $documentId) {
            $bridgeDocumentId = $this->stringValue($documentId);
            if ($bridgeDocumentId === null || isset($outputs[$bridgeDocumentId])) {
                continue;
            }

            $outputs[$bridgeDocumentId] = [
                'bridge_document_id' => $bridgeDocumentId,
                'qdrant_collection' => $qdrantCollection,
                'neo4j_namespace' => $this->stringValue($dataset?->neo4j_namespace),
                'source_id' => $source->source_id,
                'task_id' => $this->stringValue($artifact->task_id) ?? $this->stringValue($source->task_id),
                'job_id' => $this->stringValue($artifact->job_id),
                'content_hash' => $this->stringValue($source->content_hash),
                'chunk_count' => max(0, (int) ($chunkCounts[$bridgeDocumentId] ?? 0)),
                'status' => 'indexed',
                'indexed_at' => $artifact->occurred_at ?? $source->ready_at,
                'metadata_json' => [
                    'rag_ingestion_artifact_id' => $artifact->id,
                ],
            ];
        }

        return array_values($outputs);
    }

    /**
     * @param  array<string, int>  $registryChunkCounts
     * @param  array<string, mixed>  $metadata
     */
    private function chunkCount(array $registryChunkCounts, array $metadata, string $bridgeDocumentId): int
    {
        if (array_key_exists($bridgeDocumentId, $registryChunkCounts)) {
            return max(0, (int) $registryChunkCounts[$bridgeDocumentId]);
        }

        $bridgeResponse = is_array($metadata['bridge_response'] ?? null)
            ? $metadata['bridge_response']
            : [];
        $summary = is_array($bridgeResponse['summary'] ?? null)
            ? $bridgeResponse['summary']
            : [];
        $documents = is_array($summary['documents'] ?? null)
            ? $summary['documents']
            : [];
        $chunkCounts = is_array($documents['chunks_per_doc'] ?? null)
            ? $documents['chunks_per_doc']
            : [];

        return max(0, (int) ($chunkCounts[$bridgeDocumentId] ?? 0));
    }

    private function bridgeDocumentId(Document $document): ?string
    {
        $metadata = is_array($document->metadata_json) ? $document->metadata_json : [];

        return $this->stringValue($document->external_id)
            ?? $this->stringValue($metadata['document_id'] ?? null)
            ?? $this->stringValue($metadata['doc_id'] ?? null);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
