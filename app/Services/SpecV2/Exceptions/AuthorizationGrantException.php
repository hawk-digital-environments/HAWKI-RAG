<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Exceptions;

class AuthorizationGrantException extends \RuntimeException
{
    public static function documentNotFound(string $documentId): self
    {
        return new self("Document {$documentId} was not found.");
    }

    public static function groupsMustShareTenant(string $resourceId, string $tenantId): self
    {
        return new self("All granted groups for {$resourceId} must belong to tenant {$tenantId}.");
    }

    public static function invalidPermissionCheckTarget(): self
    {
        return new self('Provide exactly one of heap_id or document_id.');
    }
}
