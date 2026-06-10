<?php

declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ConversionOutputCache
{
    public function __construct(
        private PipelineEventArtifactReader $artifacts,
        private ConversionOutputPaths $paths,
    ) {
    }

    public function cachedConversion(string $sourcePath, string $contentHash): ?string
    {
        $outputDir = $this->paths->outputDirectory($sourcePath);
        $meta = $this->artifacts->readJsonArray($this->paths->metadataPath($sourcePath));
        if ($meta === null || (string) ($meta['converted_id'] ?? '') !== $contentHash) {
            return null;
        }

        $combined = (string) ($meta['combined_markdown_path'] ?? '');
        if ($combined !== '' && $this->artifacts->isFile($combined)) {
            return $combined;
        }

        foreach (($meta['files'] ?? []) as $relative) {
            $candidate = $outputDir.DIRECTORY_SEPARATOR.ltrim((string) $relative, DIRECTORY_SEPARATOR);
            if ($this->artifacts->isFile($candidate) && str_ends_with(strtolower($candidate), '.md')) {
                return $candidate;
            }
        }

        $flat = $this->paths->flatMarkdownPath($sourcePath);

        return $this->artifacts->isFile($flat) ? $flat : null;
    }
}
