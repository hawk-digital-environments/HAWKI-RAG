<?php

declare(strict_types=1);

namespace App\Services\Assistant\Repositories;

use App\Models\AssistantDocument;
use App\Models\AssistantDocumentOutput;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

#[Singleton]
readonly class AssistantDocumentOutputRepository
{
    /**
     * @return Collection<int, AssistantDocumentOutput>
     */
    public function activeForDocument(string $assistantDocumentId): Collection
    {
        return AssistantDocumentOutput::query()
            ->where('assistant_document_id', $assistantDocumentId)
            ->where('active', true)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param array<int, array<string, mixed>> $outputs
     * @return Collection<int, AssistantDocumentOutput>
     */
    public function syncActiveOutputs(AssistantDocument $document, array $outputs): Collection
    {
        $bridgeDocumentIds = [];

        foreach ($outputs as $output) {
            $bridgeDocumentId = (string) ($output['bridge_document_id'] ?? '');
            if ($bridgeDocumentId === '') {
                continue;
            }

            $bridgeDocumentIds[] = $bridgeDocumentId;

            AssistantDocumentOutput::query()->updateOrCreate(
                [
                    'assistant_document_id' => $document->assistant_document_id,
                    'bridge_document_id' => $bridgeDocumentId,
                ],
                [
                    'qdrant_collection' => $output['qdrant_collection'] ?? '',
                    'neo4j_namespace' => $output['neo4j_namespace'] ?? null,
                    'source_id' => $output['source_id'] ?? null,
                    'task_id' => $output['task_id'] ?? null,
                    'job_id' => $output['job_id'] ?? null,
                    'content_hash' => $output['content_hash'] ?? null,
                    'chunk_count' => (int) ($output['chunk_count'] ?? 0),
                    'status' => $output['status'] ?? 'indexed',
                    'active' => true,
                    'indexed_at' => $output['indexed_at'] ?? null,
                    'deleted_at' => null,
                    'metadata_json' => $output['metadata_json'] ?? null,
                ],
            );
        }

        if ($bridgeDocumentIds !== []) {
            AssistantDocumentOutput::query()
                ->where('assistant_document_id', $document->assistant_document_id)
                ->where('active', true)
                ->whereNotIn('bridge_document_id', $bridgeDocumentIds)
                ->update([
                    'active' => false,
                    'deleted_at' => Carbon::now(),
                    'updated_at' => Carbon::now(),
                ]);
        }

        return $this->activeForDocument($document->assistant_document_id);
    }

    /**
     * @return Collection<int, AssistantDocumentOutput>
     */
    public function deactivateActiveOutputs(AssistantDocument $document, Carbon $deletedAt): Collection
    {
        $outputs = $this->activeForDocument($document->assistant_document_id);

        AssistantDocumentOutput::query()
            ->where('assistant_document_id', $document->assistant_document_id)
            ->where('active', true)
            ->update([
                'active' => false,
                'status' => 'deleted',
                'deleted_at' => $deletedAt,
                'updated_at' => $deletedAt,
            ]);

        return $outputs;
    }
}
