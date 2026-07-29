<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\ManagedDocument;
use App\Models\ManagedDocumentOutput;
use App\Services\Document\Repositories\ManagedIngestionMetadataRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ManagedDocumentPayloadBuilder
{
    public function __construct(
        private ManagedIngestionMetadataRepository $metadata,
        private DocumentPayloadBuilder $documents,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(ManagedDocument $document, bool $includeDetails = true): array
    {
        $managedDocumentId = $document->documentId()->value;
        $sourceUpdatedAt = $document->source_updated_at?->toIso8601String();
        $indexedAt = $document->indexed_at?->toIso8601String();
        $createdAt = $document->created_at?->toIso8601String();
        $updatedAt = $document->updated_at?->toIso8601String();
        $activeOutputs = $document->outputs
            ->where('active', true)
            ->values();
        $primaryOutput = $activeOutputs->first();
        $backing = $this->backingDocumentPayload($document, $includeDetails);
        $managedMetadata = is_array($document->metadata_json) ? $document->metadata_json : [];
        $contentType = $this->stringValue($backing['content_type'] ?? null);
        $qdrantCollection = $this->stringValue($backing['qdrant_collection'] ?? null)
            ?? $this->stringValue($primaryOutput?->qdrant_collection);
        $neo4jNamespace = $this->stringValue($backing['neo4j_namespace'] ?? null)
            ?? $this->stringValue($primaryOutput?->neo4j_namespace);
        $payload = [
            'id' => $managedDocumentId,
            'document_id' => $managedDocumentId,
            'dataset_id' => $document->dataset_id,
            'status' => $document->status,
            'graph_enabled' => (bool) $document->graph_enabled,
            'display_name' => $document->display_name,
            'title' => $this->stringValue($backing['title'] ?? null) ?? $document->display_name ?? $managedDocumentId,
            'source_type' => $document->source_type,
            'source_url' => $document->source_url,
            'source_updated_at' => $sourceUpdatedAt,
            'source_checksum_sha256' => $document->source_checksum_sha256,
            'content_hash' => $document->source_checksum_sha256,
            'latest_document_version' => $document->latest_document_version,
            'indexed_at' => $indexedAt,
            'ingested_at' => $this->stringValue($backing['ingested_at'] ?? null) ?? $indexedAt,
            'latest_task_id' => $document->latest_task_id,
            'latest_job_id' => $document->latest_job_id,
            'latest_source_id' => $document->latest_source_id,
            'last_error' => $document->last_error,
            'metadata' => $this->arrayValue($backing['metadata'] ?? null) ?: $managedMetadata,
            'metadata_json' => $managedMetadata,
            'original_filename' => $this->stringValue($backing['original_filename'] ?? null) ?? $document->display_name,
            'content_type' => $contentType,
            'local_path' => $this->stringValue($backing['local_path'] ?? null),
            'collection' => $this->stringValue($backing['collection'] ?? null) ?? $qdrantCollection,
            'qdrant_collection' => $qdrantCollection,
            'neo4j_namespace' => $neo4jNamespace,
            'qdrant_status' => $this->stringValue($backing['qdrant_status'] ?? null) ?? $this->qdrantStatus($document),
            'neo4j_status' => $this->stringValue($backing['neo4j_status'] ?? null) ?? $this->neo4jStatus($document),
            'file_size' => $this->intValue($backing['file_size'] ?? null),
            'created_at' => $createdAt,
            'updated_at' => $this->stringValue($backing['updated_at'] ?? null) ?? $updatedAt,
            'outputs' => $activeOutputs
                ->map(fn (ManagedDocumentOutput $output): array => [
                    'bridge_document_id' => $output->bridge_document_id,
                    'qdrant_collection' => $output->qdrant_collection,
                    'neo4j_namespace' => $output->neo4j_namespace,
                    'chunk_count' => (int) $output->chunk_count,
                    'status' => $output->status,
                    'active' => (bool) $output->active,
                    'indexed_at' => $output->indexed_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'active_output_count' => $activeOutputs->count(),
            'active_chunk_count' => (int) $activeOutputs->sum(static fn (ManagedDocumentOutput $output): int => (int) $output->chunk_count),
        ];

        if (! $includeDetails) {
            return $payload;
        }

        $payload['qdrant_point_count'] = $this->intValue($backing['qdrant_point_count'] ?? null) ?? $payload['active_chunk_count'];
        $payload['neo4j_entity_count'] = $this->intValue($backing['neo4j_entity_count'] ?? null);
        $payload['neo4j_relation_count'] = $this->intValue($backing['neo4j_relation_count'] ?? null);
        $payload['neo4j_live_stats'] = $this->arrayValue($backing['neo4j_live_stats'] ?? null) ?: null;
        $payload['markdown_preview'] = $this->stringValue($backing['markdown_preview'] ?? null) ?? '';
        $payload['markdown_preview_path'] = $this->stringValue($backing['markdown_preview_path'] ?? null);
        $payload['markdown_preview_error'] = $this->stringValue($backing['markdown_preview_error'] ?? null)
            ?? ($payload['markdown_preview'] === '' ? 'No extracted Markdown preview is available.' : null);
        $payload['markdown_preview_truncated'] = (bool) ($backing['markdown_preview_truncated'] ?? false);
        $payload['related_jobs'] = is_array($backing['related_jobs'] ?? null) ? $backing['related_jobs'] : [];
        $payload['indexed_document_id'] = $this->stringValue($backing['id'] ?? null);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function backingDocumentPayload(ManagedDocument $document, bool $includeDetails): array
    {
        $sourceId = $this->stringValue($document->latest_source_id);
        if ($sourceId === null) {
            return [];
        }

        $indexedDocument = $this->metadata->documentsForSource($sourceId)->first();
        if (! $indexedDocument instanceof \App\Models\Document) {
            return [];
        }

        return $this->documents->payload($indexedDocument, includeDetails: $includeDetails);
    }

    private function qdrantStatus(ManagedDocument $document): string
    {
        return match ($document->status) {
            ManagedDocument::STATUS_INDEXED => 'indexed',
            ManagedDocument::STATUS_FAILED => 'failed',
            ManagedDocument::STATUS_DELETED => 'deleted',
            default => $document->status,
        };
    }

    private function neo4jStatus(ManagedDocument $document): string
    {
        if (! $document->graph_enabled) {
            return 'disabled';
        }

        return match ($document->status) {
            ManagedDocument::STATUS_INDEXED => 'indexed',
            ManagedDocument::STATUS_FAILED => 'failed',
            ManagedDocument::STATUS_DELETED => 'deleted',
            default => $document->status,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
