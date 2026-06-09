<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Validation;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineDataValidator
{
    public function __construct(
        private ScrapeElementValidator $scrapeElements,
        private ConvertedFilesValidator $convertedFiles,
        private MarkdownContentValidator $markdown,
        private ConversionMetadataValidator $metadata,
        private PipelineValidationValueNormalizer $values,
    ) {
    }

    /**
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function validateScrapeElement(array $data): array
    {
        return $this->scrapeElements->validate($data);
    }

    /**
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function validateConvertedFiles(array $files): array
    {
        return $this->convertedFiles->validate($files);
    }

    /**
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function validateMarkdownContent(?string $content): array
    {
        return $this->markdown->validate($content);
    }

    /**
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function validateConversionMetadata(array $metadata): array
    {
        return $this->metadata->validate($metadata);
    }

    public function firstScalar(mixed $value): ?string
    {
        return $this->values->firstScalar($value);
    }
}
