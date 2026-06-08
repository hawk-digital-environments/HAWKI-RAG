<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Values;

readonly class PipelineStoredUpload
{
    private function __construct(
        public string $originalName,
        public string $targetName,
        public string $localPath,
        public string $contentHash,
        public string $extension,
    ) {
    }

    public static function fromStoredFile(
        string $originalName,
        string $targetName,
        string $localPath,
        string $contentHash,
        string $extension,
    ): self {
        return new self($originalName, $targetName, $localPath, $contentHash, $extension);
    }
}
