<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Models\AssistantDocument;
use App\Services\Assistant\Repositories\AssistantDocumentOutputRepository;
use App\Services\Assistant\Repositories\AssistantDocumentRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;

#[Singleton]
readonly class AssistantDocumentDeleteService
{
    public function __construct(
        private AssistantDocumentRepository $documents,
        private AssistantDocumentOutputRepository $outputs,
        private AssistantDocumentSyncService $sync,
        private AssistantDocumentPayloadBuilder $payloads,
        private AssistantDocumentOutputDeletionService $deletions,
    ) {
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    public function delete(string $assistantDocumentId, ?string $idempotencyKey): ?array
    {
        $document = $this->documents->find($assistantDocumentId);
        if ($document === null) {
            return null;
        }

        $document = $this->sync->sync($document);
        $document = $this->documents->save($document, [
            'status' => AssistantDocument::STATUS_DELETING,
            'last_error' => null,
        ]);

        $activeOutputs = $this->outputs->backfillScopes(
            $document,
            $this->outputs->activeForDocument($document->assistant_document_id),
        );

        try {
            $deletion = $this->deletions->deleteActiveOutputs($activeOutputs, $idempotencyKey);
        } catch (\Throwable $exception) {
            $document = $this->documents->save($document, [
                'status' => AssistantDocument::STATUS_FAILED,
                'last_error' => $exception->getMessage(),
            ]);

            return $this->failurePayload($document, 'delete', 502, 'Failed to delete one or more indexed bridge documents.', $exception->getMessage());
        }

        $deletedAt = Carbon::now();
        if ($activeOutputs->isNotEmpty()) {
            $this->outputs->deactivateActiveOutputs($document, $deletedAt);
        }

        $document = $this->documents->save($document, [
            'status' => AssistantDocument::STATUS_DELETED,
            'deleted_at' => $deletedAt,
            'last_error' => null,
        ]);

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'operation' => [
                    'type' => 'delete',
                    'status' => 'completed',
                ],
                'document' => $this->payloads->build($document),
                'deletion' => $deletion,
            ],
        ];
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function failurePayload(
        AssistantDocument $document,
        string $operationType,
        int $status,
        string $message,
        ?string $error = null,
    ): array {
        return [
            'status' => $status,
            'payload' => [
                'success' => false,
                'operation' => [
                    'type' => $operationType,
                    'status' => AssistantDocument::STATUS_FAILED,
                ],
                'message' => $message,
                'error' => $error,
                'document' => $this->payloads->build($document),
            ],
        ];
    }
}
