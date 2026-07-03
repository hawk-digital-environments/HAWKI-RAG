<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Exceptions;

final class TenantNotFoundException extends \RuntimeException implements SpecV2ExceptionInterface
{
    public static function withId(string $tenantId): self
    {
        return new self("Tenant {$tenantId} was not found.");
    }
}
