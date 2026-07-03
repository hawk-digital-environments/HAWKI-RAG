<?php

declare(strict_types=1);

namespace App\Services\Authorization\PermissionGraph;

use App\Services\Authorization\Values\LmsDocumentRelation;
use App\Services\Authorization\Values\LmsMembership;
use App\Services\Authorization\Values\PermissionGraphRelationship;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PermissionGraphRelationshipFactory
{
    public function membership(LmsMembership $membership): PermissionGraphRelationship
    {
        return new PermissionGraphRelationship(
            resourceType: 'course',
            resourceId: $this->scopedId($membership->provider, $membership->courseId),
            relation: $membership->relation(),
            subjectType: 'user',
            subjectId: $this->scopedId($membership->provider, $membership->externalUserId),
        );
    }

    public function documentRelation(LmsDocumentRelation $relation): PermissionGraphRelationship
    {
        return new PermissionGraphRelationship(
            resourceType: 'document',
            resourceId: $this->safe($relation->documentId),
            relation: 'course',
            subjectType: 'course',
            subjectId: $this->scopedId($relation->provider, $relation->courseId),
        );
    }

    public function scopedId(string $provider, string $externalId): string
    {
        return $this->safe($provider).'__'.$this->safe($externalId);
    }

    public function safe(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\/|\\-=+]/', '_', trim($value)) ?: '';

        return $safe !== '' ? $safe : 'unknown';
    }
}
