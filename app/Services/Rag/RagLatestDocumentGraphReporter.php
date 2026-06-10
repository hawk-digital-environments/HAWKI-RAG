<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Services\Document\DocumentRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class RagLatestDocumentGraphReporter
{
    public function __construct(private DocumentRepository $documents)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function report(): ?array
    {
        $document = $this->documents->latestCompleted();
        if (! $document) {
            return null;
        }

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
}
