<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class AssistantDocumentDeleteService
{
    public function __construct(
        private AssistantDocumentService $documents,
    ) {
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    public function delete(string $assistantDocumentId, ?string $idempotencyKey): ?array
    {
        return $this->documents->delete($assistantDocumentId, $idempotencyKey);
    }
}
