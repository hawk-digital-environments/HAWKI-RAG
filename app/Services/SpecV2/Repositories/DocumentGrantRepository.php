<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Repositories;

use App\Models\SpecV2\DocumentGrant;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class DocumentGrantRepository
{
    /**
     * @return Collection<int, DocumentGrant>
     */
    public function listForDocument(string $documentId): Collection
    {
        return DocumentGrant::query()
            ->with('group')
            ->where('document_id', $documentId)
            ->orderBy('group_id')
            ->get();
    }

    /**
     * @param list<string> $groupIds
     */
    public function replace(string $documentId, array $groupIds): void
    {
        DocumentGrant::query()->where('document_id', $documentId)->delete();
        $this->add($documentId, $groupIds);
    }

    /**
     * @param list<string> $groupIds
     */
    public function add(string $documentId, array $groupIds): void
    {
        foreach ($groupIds as $groupId) {
            DocumentGrant::query()->updateOrCreate([
                'document_id' => $documentId,
                'group_id' => $groupId,
            ]);
        }
    }

    /**
     * @param list<string> $groupIds
     */
    public function remove(string $documentId, array $groupIds): void
    {
        if ($groupIds === []) {
            return;
        }

        DocumentGrant::query()
            ->where('document_id', $documentId)
            ->whereIn('group_id', $groupIds)
            ->delete();
    }
}
