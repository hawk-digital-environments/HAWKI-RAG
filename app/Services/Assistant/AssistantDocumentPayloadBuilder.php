<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Models\AssistantDocument;
use App\Models\AssistantDocumentOutput;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class AssistantDocumentPayloadBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(AssistantDocument $document): array
    {
        $activeOutputs = $document->outputs
            ->where('active', true)
            ->values();

        return [
            'assistant_document_id' => $document->assistant_document_id,
            'dataset_id' => $document->dataset_id,
            'status' => $document->status,
            'graph_enabled' => (bool) $document->graph_enabled,
            'display_name' => $document->display_name,
            'source_type' => $document->source_type,
            'source_url' => $document->source_url,
            'source_updated_at' => $document->source_updated_at?->toIso8601String(),
            'source_checksum_sha256' => $document->source_checksum_sha256,
            'latest_document_version' => $document->latest_document_version,
            'indexed_at' => $document->indexed_at?->toIso8601String(),
            'latest_task_id' => $document->latest_task_id,
            'latest_job_id' => $document->latest_job_id,
            'latest_source_id' => $document->latest_source_id,
            'last_error' => $document->last_error,
            'metadata_json' => $document->metadata_json,
            'outputs' => $activeOutputs
                ->map(fn (AssistantDocumentOutput $output): array => [
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
            'active_chunk_count' => (int) $activeOutputs->sum(static fn (AssistantDocumentOutput $output): int => (int) $output->chunk_count),
        ];
    }
}
