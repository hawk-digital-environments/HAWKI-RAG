<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Services\Assistant\Repositories\AssistantDocumentRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class AssistantDocumentService
{
    public function __construct(
        public AssistantDocumentCreateService $create,
        public AssistantDocumentUpdateService $update,
        public AssistantDocumentDeleteService $delete,
        private AssistantDocumentRepository $documents,
        private AssistantDocumentSyncService $sync,
        private AssistantDocumentPayloadBuilder $payloads,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function create(array $input, ?\Illuminate\Http\UploadedFile $file, ?string $idempotencyKey): array
    {
        return $this->create->create($input, $file, $idempotencyKey);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<\Illuminate\Http\UploadedFile> $files
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function createBatch(array $input, array $files, ?string $idempotencyKey): array
    {
        return $this->create->createBatch($input, $files, $idempotencyKey);
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    public function show(string $assistantDocumentId): ?array
    {
        $document = $this->documents->find($assistantDocumentId);
        if ($document === null) {
            return null;
        }

        $document = $this->sync->sync($document);

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'document' => $this->payloads->build($document),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    public function update(string $assistantDocumentId, array $input, ?\Illuminate\Http\UploadedFile $file, ?string $idempotencyKey): ?array
    {
        return $this->update->update($assistantDocumentId, $input, $file, $idempotencyKey);
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    public function delete(string $assistantDocumentId, ?string $idempotencyKey): ?array
    {
        return $this->delete->delete($assistantDocumentId, $idempotencyKey);
    }
}
