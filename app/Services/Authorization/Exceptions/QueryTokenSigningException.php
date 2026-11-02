<?php

declare(strict_types=1);

namespace App\Services\Authorization\Exceptions;

final class QueryTokenSigningException extends \RuntimeException implements AuthorizationExceptionInterface
{
    public static function unavailable(): self
    {
        return new self('Query authentication is not available.');
    }
}
