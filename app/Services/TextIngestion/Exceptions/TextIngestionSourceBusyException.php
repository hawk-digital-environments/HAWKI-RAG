<?php

declare(strict_types=1);

namespace App\Services\TextIngestion\Exceptions;

final class TextIngestionSourceBusyException extends \RuntimeException implements TextIngestionExceptionInterface
{
    public static function forSource(string $sourceId): self
    {
        return new self(
            "The text ingestion source [{$sourceId}] is already being processed.",
        );
    }
}
