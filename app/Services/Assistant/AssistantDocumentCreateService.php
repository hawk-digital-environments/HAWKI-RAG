<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\UploadedFile;

#[Singleton]
readonly class AssistantDocumentCreateService
{
    public function __construct(
        private AssistantDocumentService $documents,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function create(array $input, ?UploadedFile $file, ?string $idempotencyKey): array
    {
        return $this->documents->create($input, $file, $idempotencyKey);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<UploadedFile> $files
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function createBatch(array $input, array $files, ?string $idempotencyKey): array
    {
        return $this->documents->createBatch($input, $files, $idempotencyKey);
    }
}
