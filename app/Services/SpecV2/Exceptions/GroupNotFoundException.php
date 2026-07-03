<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Exceptions;

final class GroupNotFoundException extends \RuntimeException implements SpecV2ExceptionInterface
{
    public static function withId(string $groupId): self
    {
        return new self("Group {$groupId} was not found.");
    }
}
