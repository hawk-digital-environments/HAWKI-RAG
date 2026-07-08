<?php

declare(strict_types=1);

namespace App\Services\Assistant\Values;

use App\Models\AssistantDocument;
use App\Models\IngestionSource;
use Illuminate\Support\Carbon;

readonly class AssistantDocumentPipelineState
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
    public static function fromUploadPayload(array $uploadPayload, ?IngestionSource $source): self
    {
        $indexStatus = $source?->index_status;

        return new self(
            self::stringValue($uploadPayload['sourceId'] ?? null),
            self::stringValue($uploadPayload['taskId'] ?? null),
            self::stringValue($uploadPayload['jobId'] ?? null),
            self::stringValue($source?->document_version),
            self::stringValue($source?->content_hash),
            match ($indexStatus) {
                IngestionSource::STATUS_READY => AssistantDocument::STATUS_INDEXED,
                IngestionSource::STATUS_FAILED, IngestionSource::STATUS_CANCELLED => AssistantDocument::STATUS_FAILED,
                default => AssistantDocument::STATUS_PROCESSING,
            },
            self::sourceError($source),
            $indexStatus === IngestionSource::STATUS_READY ? ($source?->ready_at ?? Carbon::now()) : null,
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
