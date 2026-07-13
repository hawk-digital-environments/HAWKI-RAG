<?php

declare(strict_types=1);

namespace App\Services\Document\Values;

use App\Models\IngestionSource;
use App\Models\ManagedDocument;
use Illuminate\Support\Carbon;

readonly class ManagedDocumentPipelineState
{
    private function __construct(
        public ?string $sourceId,
        public ?string $taskId,
        public ?string $jobId,
        public ?string $documentVersion,
        public ?string $checksumSha256,
        public string $status,
        public ?string $lastError,
        public ?Carbon $indexedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $uploadPayload
     */
    public static function fromUploadPayload(array $uploadPayload, ?IngestionSource $source, ?Carbon $readyAtFallback = null): self
    {
        $indexStatus = $source?->index_status;

        return new self(
            self::stringValue($uploadPayload['source_id'] ?? $uploadPayload['sourceId'] ?? null),
            self::stringValue($uploadPayload['task_id'] ?? $uploadPayload['taskId'] ?? null),
            self::stringValue($uploadPayload['job_id'] ?? $uploadPayload['jobId'] ?? null),
            self::stringValue($source?->document_version),
            self::stringValue($source?->content_hash),
            match ($indexStatus) {
                IngestionSource::STATUS_READY => ManagedDocument::STATUS_INDEXED,
                IngestionSource::STATUS_FAILED, IngestionSource::STATUS_CANCELLED => ManagedDocument::STATUS_FAILED,
                default => ManagedDocument::STATUS_PROCESSING,
            },
            self::sourceError($source),
            $indexStatus === IngestionSource::STATUS_READY ? ($source?->ready_at ?? $readyAtFallback) : null,
        );
    }

    private static function sourceError(?IngestionSource $source): ?string
    {
        $metadata = is_array($source?->metadata) ? $source->metadata : [];

        return self::stringValue($metadata['error'] ?? null);
    }

    private static function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
