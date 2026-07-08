<?php

declare(strict_types=1);

namespace App\Services\Assistant\Repositories;

use App\Models\AssistantDocument;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;

#[Singleton]
readonly class AssistantDocumentRepository
{
    public function nextAssistantDocumentId(): string
    {
        return 'adoc_'.Str::lower((string) Str::ulid());
    }

    public function find(string $assistantDocumentId): ?AssistantDocument
    {
        return AssistantDocument::query()
            ->with(['outputs' => fn ($query) => $query->orderByDesc('active')->orderBy('id')])
            ->where('assistant_document_id', $assistantDocumentId)
            ->first();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): AssistantDocument
    {
        return AssistantDocument::query()->create($attributes)->refresh();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function save(AssistantDocument $document, array $attributes): AssistantDocument
    {
        $document->forceFill($attributes)->save();

        return $this->find($document->assistant_document_id) ?? $document->refresh();
    }
}
