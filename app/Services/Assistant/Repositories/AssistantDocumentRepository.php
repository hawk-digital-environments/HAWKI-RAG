<?php

declare(strict_types=1);

namespace App\Services\Assistant\Repositories;

use App\Models\AssistantDocument;
use App\Services\Document\Repositories\ManagedDocumentRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class AssistantDocumentRepository
{
    public function __construct(
        private ManagedDocumentRepository $documents,
    ) {
    }

    public function nextAssistantDocumentId(): string
    {
        return $this->documents->nextManagedDocumentId();
    }

    public function find(string $assistantDocumentId): ?AssistantDocument
    {
        return $this->documents->find($assistantDocumentId);
    }

    /**
     * @param array<string, mixed> $filters
     * @return Collection<int, AssistantDocument>
     */
    public function list(array $filters, int $limit): Collection
    {
        return $this->documents->list($filters, $limit);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): AssistantDocument
    {
        return $this->documents->create($attributes);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function save(AssistantDocument $document, array $attributes): AssistantDocument
    {
        return $this->documents->save($document, $attributes);
    }
}
