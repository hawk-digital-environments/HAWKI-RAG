<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Exceptions;

final class AccessDeniedException extends \RuntimeException implements SpecV2ExceptionInterface
{
    public static function forAction(string $action, string $resourceType, string $resourceId): self
    {
        return new self(sprintf(
            'You are not allowed to %s %s [%s].',
            $action,
            $resourceType,
            $resourceId,
        ));
    }
}
