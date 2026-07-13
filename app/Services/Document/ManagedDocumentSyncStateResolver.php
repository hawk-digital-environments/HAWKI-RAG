<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\Document;
use App\Models\IngestionSource;
use App\Models\ManagedDocument;
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
        private ClockInterface $clock = new Clock(),
    ) {
    }

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

            return new ManagedDocumentSyncState($attributes, $this->resolvedOutputs($source->source_id));
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
    private function resolvedOutputs(string $sourceId): array
    {
        $documents = $this->metadata->documentsForSource($sourceId);
        if ($documents->isEmpty()) {
            return [];
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
                'chunk_count' => (int) ($chunkCounts[$bridgeDocumentId] ?? 0),
                'status' => 'indexed',
                'indexed_at' => $document->updated_at ?? $document->created_at,
                'metadata_json' => $metadata,
            ];
        }

        return array_values($outputs);
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
