<?php

declare(strict_types=1);

namespace App\Services\Authorization\Connectors;

use App\Services\Authorization\Contracts\LmsPermissionConnector;
use App\Services\Authorization\Values\LmsDocumentRelation;
use App\Services\Authorization\Values\LmsMembership;
use App\Services\Authorization\Values\LmsUserIdentity;

readonly class UnsupportedLmsPermissionConnector implements LmsPermissionConnector
{
    public function __construct(private string $providerId) {}

    public function providerId(): string
    {
        return $this->providerId;
    }

    public function resolveUser(string $issuer, string $subject, array $claims): LmsUserIdentity
    {
        return new LmsUserIdentity($this->providerId, $subject);
    }

    public function membershipsForUser(LmsUserIdentity $user): iterable
    {
        return [];
    }

    public function documentsForCourse(string $courseId): iterable
    {
        return [];
    }
}
