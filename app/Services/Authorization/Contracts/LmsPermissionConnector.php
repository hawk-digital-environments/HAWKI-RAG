<?php

declare(strict_types=1);

namespace App\Services\Authorization\Contracts;

use App\Services\Authorization\Values\LmsDocumentRelation;
use App\Services\Authorization\Values\LmsMembership;
use App\Services\Authorization\Values\LmsUserIdentity;

interface LmsPermissionConnector
{
    public function providerId(): string;

    /**
     * @param array<string, mixed> $claims
     */
    public function resolveUser(string $issuer, string $subject, array $claims): LmsUserIdentity;

    /**
     * @return iterable<int, LmsMembership>
     */
    public function membershipsForUser(LmsUserIdentity $user): iterable;

    /**
     * @return iterable<int, LmsDocumentRelation>
     */
    public function documentsForCourse(string $courseId): iterable;
}
