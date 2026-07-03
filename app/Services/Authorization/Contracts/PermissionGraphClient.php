<?php

declare(strict_types=1);

namespace App\Services\Authorization\Contracts;

use App\Services\Authorization\Values\PermissionGraphRelationship;

interface PermissionGraphClient
{
    public function backendId(): string;

    /**
     * @param list<PermissionGraphRelationship> $relationships
     * @return array<string, mixed>
     */
    public function writeRelationships(array $relationships): array;

    /**
     * @param list<string> $documentIds
     * @return array<string, bool>
     */
    public function batchCheckDocuments(string $provider, string $externalUserId, array $documentIds): array;
}
