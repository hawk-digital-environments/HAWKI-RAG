<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Services\Document\Values\ManagedDocumentId;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\UploadedFile;

#[Singleton]
readonly class UnifiedDocumentService
{
    public function __construct(
        private ManagedDocumentService $managed,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function create(array $input, ?UploadedFile $file, ?string $idempotencyKey): array
    {
        return $this->managed->create($input, $file, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<UploadedFile>  $files
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function createBatch(array $input, array $files, ?string $idempotencyKey): array
    {
        return $this->managed->createBatch($input, $files, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 100, array $filters = []): array
    {
        return $this->managed->list($limit, $filters);
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    public function show(string $documentId): ?array
    {
        if (! $this->isManagedDocumentId($documentId)) {
            return null;
        }

        return $this->managed->show($documentId);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    public function update(string $documentId, array $input, ?UploadedFile $file, ?string $idempotencyKey): ?array
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
