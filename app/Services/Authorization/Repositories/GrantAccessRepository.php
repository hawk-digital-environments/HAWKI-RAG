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

        $groupDocumentIds = DocumentGrant::query()
            ->join('group_members', 'group_members.group_id', '=', 'document_grants.group_id')
            ->whereIn('group_members.internal_user_id', $internalUserIds)
            ->pluck('document_grants.document_id')
            ->all();

        $directDocumentIds = DocumentGrant::query()
            ->whereIn('document_grants.internal_user_id', $internalUserIds)
            ->pluck('document_grants.document_id')
            ->all();

        $groupHeapDocumentIds = Document::query()
            ->select('documents.id')
            ->distinct()
            ->join('heap_grants', 'heap_grants.heap_id', '=', 'documents.dataset_id')
            ->join('group_members', 'group_members.group_id', '=', 'heap_grants.group_id')
            ->whereIn('group_members.internal_user_id', $internalUserIds)
            ->pluck('documents.id')
            ->all();

        $directHeapDocumentIds = Document::query()
            ->select('documents.id')
            ->distinct()
            ->join('heap_grants', 'heap_grants.heap_id', '=', 'documents.dataset_id')
            ->whereIn('heap_grants.internal_user_id', $internalUserIds)
            ->pluck('documents.id')
            ->all();

        return array_values(array_unique(array_filter([
            ...$groupDocumentIds,
            ...$directDocumentIds,
            ...$groupHeapDocumentIds,
            ...$directHeapDocumentIds,
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

        $hasGroupDocumentGrant = DocumentGrant::query()
            ->join('group_members', 'group_members.group_id', '=', 'document_grants.group_id')
            ->where('document_grants.document_id', $documentId)
            ->whereIn('group_members.internal_user_id', $internalUserIds)
            ->exists();

        if ($hasGroupDocumentGrant) {
            return true;
        }

        $hasDirectDocumentGrant = DocumentGrant::query()
            ->where('document_id', $documentId)
            ->whereIn('internal_user_id', $internalUserIds)
            ->exists();

        if ($hasDirectDocumentGrant) {
            return true;
        }

        $hasGroupHeapGrant = Document::query()
            ->join('heap_grants', 'heap_grants.heap_id', '=', 'documents.dataset_id')
            ->join('group_members', 'group_members.group_id', '=', 'heap_grants.group_id')
            ->where('documents.id', $documentId)
            ->whereIn('group_members.internal_user_id', $internalUserIds)
            ->exists();

        if ($hasGroupHeapGrant) {
            return true;
        }

        return Document::query()
            ->join('heap_grants', 'heap_grants.heap_id', '=', 'documents.dataset_id')
            ->where('documents.id', $documentId)
            ->whereIn('heap_grants.internal_user_id', $internalUserIds)
            ->exists();
    }

    /**
     * @param list<string> $internalUserIds
     * @return list<string>
     */
    public function accessibleHeapIdsForInternalUsers(array $internalUserIds): array
    {
        $internalUserIds = $this->normalizedIds($internalUserIds);
        if ($internalUserIds === []) {
            return [];
        }

        $groupHeapIds = HeapGrant::query()
            ->join('group_members', 'group_members.group_id', '=', 'heap_grants.group_id')
            ->whereIn('group_members.internal_user_id', $internalUserIds)
            ->pluck('heap_grants.heap_id')
            ->all();

        $directHeapIds = HeapGrant::query()
            ->whereIn('internal_user_id', $internalUserIds)
            ->pluck('heap_id')
            ->all();

        $documentHeapIds = Document::query()
            ->select('documents.dataset_id')
            ->distinct()
            ->join('document_grants', 'document_grants.document_id', '=', 'documents.id')
            ->whereIn('document_grants.internal_user_id', $internalUserIds)
            ->pluck('documents.dataset_id')
            ->all();

        $groupDocumentHeapIds = Document::query()
            ->select('documents.dataset_id')
            ->distinct()
            ->join('document_grants', 'document_grants.document_id', '=', 'documents.id')
            ->join('group_members', 'group_members.group_id', '=', 'document_grants.group_id')
            ->whereIn('group_members.internal_user_id', $internalUserIds)
            ->pluck('documents.dataset_id')
            ->all();

        return array_values(array_unique(array_filter([
            ...$groupHeapIds,
            ...$directHeapIds,
            ...$documentHeapIds,
            ...$groupDocumentHeapIds,
        ], fn (mixed $id): bool => is_string($id) && trim($id) !== '')));
    }

    /**
     * @param list<string> $internalUserIds
     */
    public function canViewHeap(string $heapId, array $internalUserIds): bool
    {
        $internalUserIds = $this->normalizedIds($internalUserIds);
        if ($internalUserIds === []) {
            return false;
        }

        $hasGroupHeapGrant = HeapGrant::query()
            ->join('group_members', 'group_members.group_id', '=', 'heap_grants.group_id')
            ->where('heap_grants.heap_id', $heapId)
            ->whereIn('group_members.internal_user_id', $internalUserIds)
            ->exists();

        if ($hasGroupHeapGrant) {
            return true;
        }

        $hasDirectHeapGrant = HeapGrant::query()
            ->where('heap_id', $heapId)
            ->whereIn('internal_user_id', $internalUserIds)
            ->exists();

        if ($hasDirectHeapGrant) {
            return true;
        }

        $hasDirectDocumentInHeap = Document::query()
            ->join('document_grants', 'document_grants.document_id', '=', 'documents.id')
            ->where('documents.dataset_id', $heapId)
            ->whereIn('document_grants.internal_user_id', $internalUserIds)
            ->exists();

        if ($hasDirectDocumentInHeap) {
            return true;
        }

        return Document::query()
            ->join('document_grants', 'document_grants.document_id', '=', 'documents.id')
            ->join('group_members', 'group_members.group_id', '=', 'document_grants.group_id')
            ->where('documents.dataset_id', $heapId)
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
