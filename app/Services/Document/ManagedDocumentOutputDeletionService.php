<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Services\Document\Clients\ManagedDocumentBridgeClient;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class ManagedDocumentOutputDeletionService
{
    public function __construct(
        private ManagedDocumentBridgeClient $bridge,
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
            $response = $this->bridge->deleteDocument(
                $output->bridge_document_id,
                $idempotencyKey,
                $this->stringValue($output->qdrant_collection),
                $this->stringValue($output->neo4j_namespace),
            );
            $qdrant[] = [
                'bridge_document_id' => $output->bridge_document_id,
                'collection' => $response['qdrant']['collection'] ?? $this->stringValue($output->qdrant_collection),
                'deleted_points' => $response['qdrant']['deleted_points'] ?? null,
                'result' => $response['qdrant'] ?? null,
            ];

            $neo4jPayload = $response['neo4j'] ?? null;
            if (is_array($neo4jPayload)) {
                $neo4j[] = array_merge([
                    'bridge_document_id' => $output->bridge_document_id,
                    'namespace' => $neo4jPayload['namespace'] ?? $this->stringValue($output->neo4j_namespace),
                ], $neo4jPayload);
                continue;
            }

            $neo4j[] = [
                'bridge_document_id' => $output->bridge_document_id,
                'namespace' => $this->stringValue($output->neo4j_namespace),
                'result' => $neo4jPayload,
            ];
        }

        return [
            'bridge_documents_deleted' => $activeOutputs->count(),
            'qdrant' => $qdrant,
            'neo4j' => $neo4j,
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
