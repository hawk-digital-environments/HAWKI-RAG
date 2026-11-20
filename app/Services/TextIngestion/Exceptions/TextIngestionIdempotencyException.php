<?php

declare(strict_types=1);

namespace App\Services\TextIngestion\Exceptions;

final class TextIngestionIdempotencyException extends \RuntimeException implements TextIngestionExceptionInterface
{
    public static function payloadMismatch(string $idempotencyKey): self
    {
        return new self(
            "The idempotency key [{$idempotencyKey}] was already used with a different request.",
        );
    }

    public static function incompleteTask(string $taskId): self
    {
        return new self(
            "The existing text ingestion task [{$taskId}] has no associated ingestion job.",
        );
    }

    public static function incompleteSource(string $sourceId): self
    {
        return new self(
            "The existing text ingestion source [{$sourceId}] could not be found.",
        );
    }

    public static function incompleteArtifact(string $jobId): self
    {
        return new self(
            "The existing text ingestion job [{$jobId}] has no persisted Markdown artifact.",
        );
    }
}
