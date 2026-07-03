<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Exceptions;

final class ApplicationNotFoundException extends \RuntimeException implements SpecV2ExceptionInterface
{
    public static function withId(string $applicationId): self
    {
        return new self("Application {$applicationId} was not found.");
    }
}
