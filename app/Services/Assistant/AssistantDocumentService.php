<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Services\Document\ManagedDocumentService;
use Illuminate\Container\Attributes\Singleton;

/**
 * @deprecated Compatibility adapter for legacy /api/assistant/documents routes.
 */
#[Singleton]
readonly class AssistantDocumentService
{
    public function __construct(
        private ManagedDocumentService $documents,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function create(array $input, ?\Illuminate\Http\UploadedFile $file, ?string $idempotencyKey): array
    {
        return $this->legacyPayload($this->documents->create($input, $file, $idempotencyKey));
    }

    /**
     * @param array<string, mixed> $input
     * @param list<\Illuminate\Http\UploadedFile> $files
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function createBatch(array $input, array $files, ?string $idempotencyKey): array
    {
        return $this->legacyPayload($this->documents->createBatch($input, $files, $idempotencyKey));
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 100, array $filters = []): array
    {
        return array_map($this->legacyDocument(...), $this->documents->list($limit, $filters));
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    public function show(string $assistantDocumentId): ?array
    {
        return $this->legacyNullablePayload($this->documents->show($assistantDocumentId));
    }

    /**
     * @param array<string, mixed> $input
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    public function update(string $assistantDocumentId, array $input, ?\Illuminate\Http\UploadedFile $file, ?string $idempotencyKey): ?array
    {
        return $this->legacyNullablePayload($this->documents->update($assistantDocumentId, $input, $file, $idempotencyKey));
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    public function delete(string $assistantDocumentId, ?string $idempotencyKey): ?array
    {
        return $this->legacyNullablePayload($this->documents->delete($assistantDocumentId, $idempotencyKey));
    }

    /**
     * @param array{status:int,payload:array<string,mixed>} $result
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function legacyPayload(array $result): array
    {
        $result['payload'] = $this->decoratePayload($result['payload']);

        return $result;
    }

    /**
     * @param array{status:int,payload:array<string,mixed>}|null $result
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    private function legacyNullablePayload(?array $result): ?array
    {
        if ($result === null) {
            return null;
        }

        return $this->legacyPayload($result);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function decoratePayload(array $payload): array
    {
        if (isset($payload['document']) && is_array($payload['document'])) {
            $payload['document'] = $this->legacyDocument($payload['document']);
        }

        if (! isset($payload['items']) || ! is_array($payload['items'])) {
            return $payload;
        }

        $payload['items'] = array_map(function (mixed $item): mixed {
            if (! is_array($item)) {
                return $item;
            }

            if (isset($item['result']) && is_array($item['result'])) {
                $item['result'] = $this->decoratePayload($item['result']);
            }

            return $item;
        }, $payload['items']);

        return $payload;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function legacyDocument(array $document): array
    {
        $assistantDocumentId = $this->stringValue($document['document_id'] ?? $document['documentId'] ?? $document['id'] ?? null);
        if ($assistantDocumentId !== null) {
            $document['assistant_document_id'] = $assistantDocumentId;
        }

        return $document;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
