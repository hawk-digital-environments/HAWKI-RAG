<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Services\Assistant\Clients\AssistantDocumentBridgeClient;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class AssistantDocumentOutputDeletionService
{
    public function __construct(
        private AssistantDocumentBridgeClient $bridge,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteActiveOutputs(Collection $activeOutputs, ?string $idempotencyKey): array
    {
        $qdrant = [];
        $neo4j = [];

        foreach ($activeOutputs as $output) {
            $response = $this->bridge->deleteDocument($output->bridge_document_id, $idempotencyKey);
            $qdrant[] = [
                'bridge_document_id' => $output->bridge_document_id,
                'result' => $response['qdrant'] ?? null,
            ];

            $neo4jPayload = $response['neo4j'] ?? null;
            if (is_array($neo4jPayload)) {
                $neo4j[] = array_merge([
                    'bridge_document_id' => $output->bridge_document_id,
                ], $neo4jPayload);
                continue;
            }

            $neo4j[] = [
                'bridge_document_id' => $output->bridge_document_id,
                'result' => $neo4jPayload,
            ];
        }

        return [
            'bridge_documents_deleted' => $activeOutputs->count(),
            'qdrant' => $qdrant,
            'neo4j' => $neo4j,
        ];
    }
}
