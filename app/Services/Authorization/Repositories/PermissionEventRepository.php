<?php

declare(strict_types=1);

namespace App\Services\Authorization\Repositories;

use App\Models\AuthorizationPermissionEvent;
use App\Services\Authorization\Values\LmsDocumentRelation;
use App\Services\Authorization\Values\LmsMembership;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PermissionEventRepository
{
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
}
