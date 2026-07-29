<?php

declare(strict_types=1);

namespace App\Services\Profile\Exceptions;

class ProfileTokenException extends \RuntimeException implements ProfileExceptionInterface
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function missingAuthenticatedUser(): self
    {
        return new self('Profile API token operation failed because there is no authenticated user.');
    }

    public static function revokeFailed(int $tokenId, \Throwable $previous): self
    {
        return new self("Profile API token {$tokenId} could not be revoked.", $previous);
    }
}
