<?php

declare(strict_types=1);

namespace App\Services\Authorization\Repositories;

use App\Models\AuthorizationPermissionEvent;
use App\Services\Authorization\Values\LmsDocumentRelation;
use App\Services\Authorization\Values\LmsMembership;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\Eloquent\Builder;

#[Singleton]
readonly class PermissionEventRepository
{
    public function __construct(
        private GrantAccessRepository $grants,
    ) {}

    public function recordMembership(LmsMembership $membership): void
    {
        AuthorizationPermissionEvent::query()->updateOrCreate([
            'provider' => $membership->provider,
            'external_user_id' => $membership->externalUserId,
            'course_id' => $membership->courseId,
            'role' => $membership->role,
            'document_id' => null,
        ], [
            'source_updated_at' => $membership->sourceUpdatedAt,
            'payload' => ['type' => 'membership'],
        ]);
    }

    public function recordDocumentRelation(LmsDocumentRelation $relation): void
    {
        AuthorizationPermissionEvent::query()->updateOrCreate([
            'provider' => $relation->provider,
            'course_id' => $relation->courseId,
            'document_id' => $relation->documentId,
            'external_user_id' => null,
            'role' => null,
        ], [
            'source_updated_at' => $relation->sourceUpdatedAt,
            'payload' => ['type' => 'document_relation'],
        ]);
    }

    /**
     * @param list<array{provider?: string, user_id?: string, internal_user_id?: string}> $subjects
     * @return list<string>
     */
    public function accessibleDocumentIdsForSubjects(array $subjects): array
    {
        if ($subjects === []) {
            return [];
        }

        $eventSubjects = array_values(array_filter($subjects, static fn (array $subject): bool => isset($subject['provider'], $subject['user_id'])));
        $grantSubjects = array_values(array_unique(array_filter(array_map(
            static fn (array $subject): ?string => isset($subject['internal_user_id']) && is_string($subject['internal_user_id']) && trim($subject['internal_user_id']) !== ''
                ? trim($subject['internal_user_id'])
                : null,
            $subjects,
        ))));

        $documentIds = [];

        if ($eventSubjects !== []) {
            $memberships = AuthorizationPermissionEvent::query()
                ->select(['provider', 'course_id'])
                ->whereNull('document_id')
                ->where(function (Builder $query) use ($eventSubjects): void {
                    foreach ($eventSubjects as $subject) {
                        $query->orWhere(function (Builder $nested) use ($subject): void {
                            $nested->where('provider', (string) $subject['provider'])
                                ->where('external_user_id', (string) $subject['user_id']);
                        });
                    }
                })
                ->distinct()
                ->get();

            if (! $memberships->isEmpty()) {
                $documentIds = AuthorizationPermissionEvent::query()
                    ->whereNull('external_user_id')
                    ->whereNotNull('document_id')
                    ->where(function (Builder $query) use ($memberships): void {
                        foreach ($memberships as $membership) {
                            $query->orWhere(function (Builder $nested) use ($membership): void {
                                $nested->where('provider', (string) $membership->provider)
                                    ->where('course_id', (string) $membership->course_id);
                            });
                        }
                    })
                    ->pluck('document_id')
                    ->filter(fn (mixed $documentId): bool => is_string($documentId) && trim($documentId) !== '')
                    ->values()
                    ->all();
            }
        }

        return array_values(array_unique([
            ...$documentIds,
            ...$this->grants->accessibleDocumentIdsForInternalUsers($grantSubjects),
        ]));
    }
}
