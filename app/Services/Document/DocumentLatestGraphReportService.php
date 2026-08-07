<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\ManagedDocument;
use App\Models\RagIngestionArtifact;
use App\Services\Document\Repositories\ManagedDocumentRepository;
use App\Services\Document\Repositories\ManagedIngestionMetadataRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class DocumentLatestGraphReportService
{
    public function __construct(
        private ManagedDocumentRepository $managedDocuments,
        private ManagedDocumentSyncService $managedSync,
        private ManagedDocumentPayloadBuilder $managedPayloads,
        private ManagedIngestionMetadataRepository $ingestionMetadata,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function report(): ?array
    {
        $managedDocument = $this->managedDocuments->latestIndexed();

        if ($managedDocument !== null) {
            $managedDocument = $this->managedSync->sync($managedDocument);

            if ($managedDocument->status === ManagedDocument::STATUS_INDEXED) {
                $report = $this->managedReport(
                    $this->managedPayloads->build($managedDocument, includeDetails: false),
                    $this->latestArtifact($managedDocument),
                );

                if ($report !== null) {
                    return $report;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>|null
     */
    private function managedReport(array $document, ?RagIngestionArtifact $artifact): ?array
    {
        $documentId = $this->stringValue($document['document_id'] ?? $document['id'] ?? null);
        if ($documentId === null) {
            return null;
        }

        $summary = is_array($artifact?->summary) ? $artifact->summary : [];
        $graphPreview = is_array($artifact?->graph_preview)
            ? $artifact->graph_preview
            : $this->arrayValue($summary['graph_preview'] ?? null);
        $graphConfig = $this->arrayValue($summary['graph'] ?? null);
        $primaryOutput = $this->primaryOutput($document['outputs'] ?? null);

        return [
            'document_id' => $documentId,
            'external_id' => $this->stringValue($primaryOutput['bridge_document_id'] ?? null),
            'dataset_id' => $this->stringValue($document['dataset_id'] ?? null),
            'collection' => $this->stringValue($primaryOutput['qdrant_collection'] ?? null)
                ?? $this->stringValue($document['collection'] ?? $document['qdrant_collection'] ?? null),
            'title' => $this->stringValue($document['title'] ?? null),
            'source_url' => $this->stringValue($document['source_url'] ?? null),
            'updated_at' => $this->stringValue($document['updated_at'] ?? $document['indexed_at'] ?? null),
            'qdrant_points' => $this->intValue(data_get($summary, 'qdrant_preview.planned_points'))
                ?? $this->intValue($summary['planned_points'] ?? null)
                ?? $this->intValue(data_get($summary, 'documents.total_chunks')),
            'graph_enabled' => $this->boolValue(
                $document['graph_enabled'] ?? $graphConfig['enabled'] ?? ($graphPreview !== []),
            ),
            'graph_triplets' => $graphPreview['total_triplets'] ?? null,
            'docs_with_triplets' => $graphPreview['docs_with_triplets'] ?? null,
        ];
    }

    private function latestArtifact(ManagedDocument $document): ?RagIngestionArtifact
    {
        $sourceId = $this->stringValue($document->latest_source_id);

        return $sourceId === null ? null : $this->ingestionMetadata->latestArtifactForSource($sourceId);
    }

    /**
     * @return array<string, mixed>
     */
    private function primaryOutput(mixed $outputs): array
    {
        if (! is_array($outputs)) {
            return [];
        }

        $primary = $outputs[0] ?? null;

        return is_array($primary) ? $primary : [];
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

    private function boolValue(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function intValue(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
