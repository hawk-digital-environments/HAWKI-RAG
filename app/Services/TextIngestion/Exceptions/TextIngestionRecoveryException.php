<?php

declare(strict_types=1);

namespace App\Services\TextIngestion\Exceptions;

final class TextIngestionRecoveryException extends \RuntimeException implements TextIngestionExceptionInterface
{
    public static function missingPersistedField(string $jobId, string $field): self
    {
        return new self(
            "Direct-text job [{$jobId}] cannot be recovered because persisted field [{$field}] is missing.",
        );
    }
}
