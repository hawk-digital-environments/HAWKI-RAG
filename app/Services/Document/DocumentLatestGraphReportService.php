<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\Document;
use App\Models\ManagedDocument;
use App\Services\Document\Repositories\ManagedDocumentRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class DocumentLatestGraphReportService
{
    public function __construct(
        private ManagedDocumentRepository $managedDocuments,
        private ManagedDocumentSyncService $managedSync,
        private ManagedDocumentPayloadBuilder $managedPayloads,
        private DocumentRepository $documents,
    ) {
    }

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
                );

                if ($report !== null) {
                    return $report;
                }
            }
        }

        $document = $this->documents->latestCompleted();

        return $document ? $this->legacyReport($document) : null;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>|null
     */
    private function managedReport(array $document): ?array
    {
        $documentId = $this->stringValue($document['document_id'] ?? $document['documentId'] ?? $document['id'] ?? null);
        if ($documentId === null) {
            return null;
        }

        $metadata = $this->arrayValue($document['metadata'] ?? null);
        $bridgeResponse = $this->arrayValue($metadata['bridge_response'] ?? null);
        $summary = $this->arrayValue($bridgeResponse['summary'] ?? null);
        $graphPreview = $this->arrayValue($summary['graph_preview'] ?? null);
        $graphConfig = $this->arrayValue($summary['graph'] ?? null);
        $primaryOutput = $this->primaryOutput($document['outputs'] ?? null);

        return [
            'document_id' => $documentId,
            'external_id' => $this->stringValue($primaryOutput['bridge_document_id'] ?? $primaryOutput['bridgeDocumentId'] ?? null),
            'dataset_id' => $this->stringValue($document['dataset_id'] ?? $document['datasetId'] ?? null),
            'collection' => $this->stringValue($primaryOutput['qdrant_collection'] ?? $primaryOutput['qdrantCollection'] ?? null)
                ?? $this->stringValue($document['collection'] ?? $document['qdrantCollection'] ?? null),
            'title' => $this->stringValue($document['title'] ?? null),
            'source_url' => $this->stringValue($document['source_url'] ?? $document['sourceUrl'] ?? null),
            'updated_at' => $this->stringValue($document['updatedAt'] ?? $document['indexedAt'] ?? null),
            'qdrant_points' => $bridgeResponse['points'] ?? null,
            'graph_enabled' => $this->boolValue(
                $document['graph_enabled'] ?? $document['graphEnabled'] ?? $graphConfig['enabled'] ?? ($graphPreview !== []),
            ),
            'graph_triplets' => $graphPreview['total_triplets'] ?? null,
            'docs_with_triplets' => $graphPreview['docs_with_triplets'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function legacyReport(Document $document): array
    {
        $metadata = is_array($document->metadata_json) ? $document->metadata_json : [];
        $summary = $metadata['bridge_response']['summary'] ?? null;
        $graphPreview = is_array($summary) && is_array($summary['graph_preview'] ?? null)
            ? $summary['graph_preview']
            : null;
        $graphConfig = is_array($summary) && is_array($summary['graph'] ?? null)
            ? $summary['graph']
            : [];

        return [
            'document_id' => $document->id,
            'external_id' => $document->external_id,
            'dataset_id' => $document->dataset_id,
            'collection' => $document->collection,
            'title' => $document->title,
            'source_url' => $document->source_url,
            'updated_at' => $document->updated_at?->toIso8601String(),
            'qdrant_points' => $metadata['bridge_response']['points'] ?? null,
            'graph_enabled' => (bool) ($graphConfig['enabled'] ?? $graphPreview),
            'graph_triplets' => $graphPreview['total_triplets'] ?? null,
            'docs_with_triplets' => $graphPreview['docs_with_triplets'] ?? null,
        ];
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
}
