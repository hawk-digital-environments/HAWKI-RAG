<?php

declare(strict_types=1);

namespace App\Services\TextIngestion\Exceptions;

final class TextIngestionStorageException extends \RuntimeException implements TextIngestionExceptionInterface
{
    public static function couldNotStore(
        string $path,
        ?\Throwable $previous = null,
    ): self {
        return new self(
            "Could not store the text-ingestion artifact at [{$path}].",
            previous: $previous,
        );
    }
}
