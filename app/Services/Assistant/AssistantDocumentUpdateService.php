<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\UploadedFile;

#[Singleton]
readonly class AssistantDocumentUpdateService
{
    public function __construct(
        private AssistantDocumentService $documents,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    public function update(string $assistantDocumentId, array $input, ?UploadedFile $file, ?string $idempotencyKey): ?array
    {
        return $this->documents->update($assistantDocumentId, $input, $file, $idempotencyKey);
    }
}
