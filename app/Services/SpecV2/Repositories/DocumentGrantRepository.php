<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Repositories;

use App\Models\SpecV2\DocumentGrant;
use App\Services\SpecV2\Values\GroupMemberAssignment;
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
            ->with(['group', 'internalUser'])
            ->where('document_id', $documentId)
            ->orderBy('group_id')
            ->orderBy('user_identifier')
            ->get();
    }

    public function existsForDocument(string $documentId): bool
    {
        return DocumentGrant::query()->where('document_id', $documentId)->exists();
    }

    /**
     * @param list<string> $groupIds
     */
    public function replace(string $documentId, array $groupIds): void
    {
        $this->replaceGroups($documentId, $groupIds);
    }

    /**
     * @param list<string> $groupIds
     */
    public function add(string $documentId, array $groupIds): void
    {
        $this->addGroups($documentId, $groupIds);
    }

    /**
     * @param list<string> $groupIds
     */
    public function replaceGroups(string $documentId, array $groupIds): void
    {
        DocumentGrant::query()->where('document_id', $documentId)->whereNotNull('group_id')->delete();
        $this->addGroups($documentId, $groupIds);
    }

    /**
     * @param list<string> $groupIds
     */
    public function addGroups(string $documentId, array $groupIds): void
    {
        foreach ($groupIds as $groupId) {
            DocumentGrant::query()->updateOrCreate([
                'document_id' => $documentId,
                'group_id' => $groupId,
            ], [
                'user_identifier' => null,
                'internal_user_id' => null,
            ]);
        }
    }

    /**
     * @param list<string> $groupIds
     */
    public function remove(string $documentId, array $groupIds): void
    {
        $this->removeGroups($documentId, $groupIds);
    }

    /**
     * @param list<string> $groupIds
     */
    public function removeGroups(string $documentId, array $groupIds): void
    {
        if ($groupIds === []) {
            return;
        }

        DocumentGrant::query()
            ->where('document_id', $documentId)
            ->whereIn('group_id', $groupIds)
            ->delete();
    }

    /**
     * @param list<GroupMemberAssignment> $assignments
     */
    public function replaceUsers(string $documentId, array $assignments): void
    {
        DocumentGrant::query()->where('document_id', $documentId)->whereNotNull('user_identifier')->delete();
        $this->addUsers($documentId, $assignments);
    }

    /**
     * @param list<GroupMemberAssignment> $assignments
     */
    public function addUsers(string $documentId, array $assignments): void
    {
        foreach ($assignments as $assignment) {
            DocumentGrant::query()->updateOrCreate([
                'document_id' => $documentId,
                'user_identifier' => $assignment->userIdentifier,
            ], [
                'group_id' => null,
                'internal_user_id' => $assignment->internalUserId,
            ]);
        }
    }

    /**
     * @param list<string> $userIdentifiers
     */
    public function removeUsers(string $documentId, array $userIdentifiers): void
    {
        if ($userIdentifiers === []) {
            return;
        }

        DocumentGrant::query()
            ->where('document_id', $documentId)
            ->whereIn('user_identifier', $userIdentifiers)
            ->delete();
    }

    public function clear(string $documentId): void
    {
        DocumentGrant::query()->where('document_id', $documentId)->delete();
    }
}
