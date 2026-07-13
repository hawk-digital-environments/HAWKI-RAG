<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Services\Document\ManagedDocumentOutputDeletionService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class AssistantDocumentOutputDeletionService
{
    public function __construct(
        private ManagedDocumentOutputDeletionService $deletions,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteActiveOutputs(Collection $activeOutputs, ?string $idempotencyKey): array
    {
        return $this->deletions->deleteActiveOutputs($activeOutputs, $idempotencyKey);
    }
}
