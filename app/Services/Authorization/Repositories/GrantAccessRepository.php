<?php

declare(strict_types=1);

namespace App\Services\Authorization\Repositories;

use App\Models\Document;
use App\Models\SpecV2\DocumentGrant;
use App\Models\SpecV2\GroupMember;
use App\Models\SpecV2\HeapGrant;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class GrantAccessRepository
{
    /**
     * @param list<string> $internalUserIds
     * @return list<string>
     */
    public function accessibleDocumentIdsForInternalUsers(array $internalUserIds): array
    {
        $internalUserIds = $this->normalizedIds($internalUserIds);
        if ($internalUserIds === []) {
            return [];
        }

        $documentIds = DocumentGrant::query()
            ->join('group_members', 'group_members.group_id', '=', 'document_grants.group_id')
            ->whereIn('group_members.internal_user_id', $internalUserIds)
            ->pluck('document_grants.document_id')
            ->all();

        $heapDocumentIds = Document::query()
            ->select('documents.id')
            ->distinct()
            ->join('heap_grants', 'heap_grants.heap_id', '=', 'documents.dataset_id')
            ->join('group_members', 'group_members.group_id', '=', 'heap_grants.group_id')
            ->whereIn('group_members.internal_user_id', $internalUserIds)
            ->pluck('documents.id')
            ->all();

        return array_values(array_unique(array_filter([
            ...$documentIds,
            ...$heapDocumentIds,
        ], fn (mixed $id): bool => is_string($id) && trim($id) !== '')));
    }

    /**
     * @param list<string> $internalUserIds
     */
    public function canViewDocument(string $documentId, array $internalUserIds): bool
    {
        $internalUserIds = $this->normalizedIds($internalUserIds);
        if ($internalUserIds === []) {
            return false;
        }

        $hasDocumentGrant = DocumentGrant::query()
            ->join('group_members', 'group_members.group_id', '=', 'document_grants.group_id')
            ->where('document_grants.document_id', $documentId)
            ->whereIn('group_members.internal_user_id', $internalUserIds)
            ->exists();

        if ($hasDocumentGrant) {
            return true;
        }

        return Document::query()
            ->join('heap_grants', 'heap_grants.heap_id', '=', 'documents.dataset_id')
            ->join('group_members', 'group_members.group_id', '=', 'heap_grants.group_id')
            ->where('documents.id', $documentId)
            ->whereIn('group_members.internal_user_id', $internalUserIds)
            ->exists();
    }

    /**
     * @param list<string> $internalUserIds
     * @return list<string>
     */
    private function normalizedIds(array $internalUserIds): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $value): string => trim($value),
            $internalUserIds,
        ))));
    }
}
