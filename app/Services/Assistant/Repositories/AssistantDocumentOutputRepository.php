<?php

declare(strict_types=1);

namespace App\Services\Assistant\Repositories;

use App\Models\AssistantDocument;
use App\Models\AssistantDocumentOutput;
use App\Services\Document\Repositories\ManagedDocumentOutputRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

#[Singleton]
readonly class AssistantDocumentOutputRepository
{
    public function __construct(
        private ManagedDocumentOutputRepository $outputs,
    ) {
    }

    /**
     * @return Collection<int, AssistantDocumentOutput>
     */
    public function activeForDocument(string $assistantDocumentId): Collection
    {
        return $this->outputs->activeForDocument($assistantDocumentId);
    }

    /**
     * @param array<int, array<string, mixed>> $outputs
     * @return Collection<int, AssistantDocumentOutput>
     */
    public function syncActiveOutputs(AssistantDocument $document, array $outputs): Collection
    {
        return $this->outputs->syncActiveOutputs($document, $outputs);
    }

    /**
     * @return Collection<int, AssistantDocumentOutput>
     */
    public function deactivateActiveOutputs(AssistantDocument $document, Carbon $deletedAt): Collection
    {
        return $this->outputs->deactivateActiveOutputs($document, $deletedAt);
    }

    /**
     * @param Collection<int, AssistantDocumentOutput> $outputs
     * @return Collection<int, AssistantDocumentOutput>
     */
    public function backfillScopes(AssistantDocument $document, Collection $outputs): Collection
    {
        return $this->outputs->backfillScopes($document, $outputs);
    }
}
