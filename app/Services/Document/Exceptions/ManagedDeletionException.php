<?php

declare(strict_types=1);

namespace App\Services\Document\Exceptions;

final class ManagedDeletionException extends \RuntimeException
{
    public static function conflict(string $message): self
    {
        return new self($message);
    }
}
