<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Services\Document\Values\ManagedDocumentId;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class UnifiedDocumentService
{
    public function __construct(
        private DocumentBrowserService $browser,
        private ManagedDocumentService $managed,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function create(array $input, ?\Illuminate\Http\UploadedFile $file, ?string $idempotencyKey): array
    {
        return $this->managed->create($input, $file, $idempotencyKey);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<\Illuminate\Http\UploadedFile> $files
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function createBatch(array $input, array $files, ?string $idempotencyKey): array
    {
        return $this->managed->createBatch($input, $files, $idempotencyKey);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 100, array $filters = []): array
    {
        $managed = $this->managed->list($limit, $filters);

        return $managed !== [] ? $managed : $this->browser->list($limit, $filters);
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    public function show(string $documentId): ?array
    {
        if ($this->isManagedDocumentId($documentId)) {
            return $this->managed->show($documentId);
        }

        $document = $this->browser->show($documentId);
        if ($document === null) {
            return null;
        }

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'document' => $document,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    public function update(string $documentId, array $input, ?\Illuminate\Http\UploadedFile $file, ?string $idempotencyKey): ?array
    {
        if (! $this->isManagedDocumentId($documentId)) {
            return null;
        }

        return $this->managed->update($documentId, $input, $file, $idempotencyKey);
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    public function delete(string $documentId, ?string $idempotencyKey): ?array
    {
        if (! $this->isManagedDocumentId($documentId)) {
            return null;
        }

        return $this->managed->delete($documentId, $idempotencyKey);
    }

    private function isManagedDocumentId(string $documentId): bool
    {
        return ManagedDocumentId::looksLike($documentId);
    }
}
