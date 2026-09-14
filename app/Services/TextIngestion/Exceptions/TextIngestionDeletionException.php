<?php

declare(strict_types=1);

namespace App\Services\TextIngestion\Exceptions;

final class TextIngestionDeletionException extends \RuntimeException implements TextIngestionExceptionInterface
{
    public static function failed(\Throwable $previous): self
    {
        return new self(
            'Direct-text deletion could not be completed. Retry with the same idempotency key.',
            0,
            $previous,
        );
    }
}
