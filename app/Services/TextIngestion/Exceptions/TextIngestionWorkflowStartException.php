<?php

declare(strict_types=1);

namespace App\Services\TextIngestion\Exceptions;

final class TextIngestionWorkflowStartException extends \RuntimeException implements TextIngestionExceptionInterface
{
    public static function fromPrevious(\Throwable $previous): self
    {
        return new self(
            'The text ingestion workflow could not be confirmed. Retry with the same idempotency key.',
            previous: $previous,
        );
    }
}
