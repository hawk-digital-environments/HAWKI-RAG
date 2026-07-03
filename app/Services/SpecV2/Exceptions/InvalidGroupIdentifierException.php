<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Exceptions;

final class InvalidGroupIdentifierException extends \RuntimeException implements SpecV2ExceptionInterface
{
    public static function becauseReservedCharactersWereUsed(string $groupId): self
    {
        return new self(
            "Group ID {$groupId} is invalid. Use only lowercase letters, numbers, hyphens, and underscores."
        );
    }
}
