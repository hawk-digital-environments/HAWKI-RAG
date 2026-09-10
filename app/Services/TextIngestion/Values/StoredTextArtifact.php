<?php

declare(strict_types=1);

namespace App\Services\TextIngestion\Values;

final readonly class StoredTextArtifact
{
    private function __construct(
        public string $sourceId,
        public string $sourceUrl,
        public string $contentHash,
        public string $markdownPath,
    ) {}

    public static function fromStoredText(
        string $sourceId,
        string $sourceUrl,
        string $contentHash,
        string $markdownPath,
    ): self {
        return new self(
            sourceId: $sourceId,
            sourceUrl: $sourceUrl,
            contentHash: $contentHash,
            markdownPath: $markdownPath,
        );
    }
}
