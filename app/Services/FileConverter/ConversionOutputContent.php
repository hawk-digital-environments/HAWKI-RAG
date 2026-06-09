<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class ConversionOutputContent
{
    public function __construct(
        private ConvertedOutputWriter $outputs,
        private ClockInterface $clock = new Clock,
    ) {
    }

    /**
     * @param array<string, string> $files
     */
    public function pickMarkdownContent(array $files): ?string
    {
        foreach ($files as $relative => $content) {
            if (str_ends_with(strtolower($relative), '.md')) {
                return $content;
            }
        }

        if ($files === []) {
            return null;
        }

        return implode("\n\n", array_values($files));
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function loadMarkdownFromMeta(array $meta, string $destDir): ?string
    {
        $files = $meta['files'] ?? [];
        if (! is_array($files)) {
            return null;
        }

        foreach ($files as $relative) {
            if (! is_string($relative) || ! str_ends_with(strtolower($relative), '.md')) {
                continue;
            }

            $path = $destDir.'/'.ltrim($relative, '/');
            if (is_file($path)) {
                return $this->outputs->readFile($path);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public function normalizeCachedMetadata(
        array $meta,
        string $sourcePath,
        string $destDir,
        string $convertedId,
        string $docTitle,
        string $jobId
    ): array {
        $meta['pipeline_job_id'] = $jobId;
        $meta['converted_id'] = $convertedId;
        $meta['doc_id'] = $meta['doc_id'] ?? $convertedId;
        $meta['title'] = $meta['title'] ?? $docTitle;
        $meta['source_file'] = $meta['source_file'] ?? ($meta['source_pdf'] ?? $sourcePath);
        $meta['source_pdf'] = $meta['source_pdf'] ?? $sourcePath;
        $meta['output_dir'] = $meta['output_dir'] ?? $destDir;
        $meta['converted_at'] = $meta['converted_at'] ?? $this->clock->now()->format(\DateTimeInterface::ATOM);

        if (! isset($meta['files']) || ! is_array($meta['files']) || $meta['files'] === []) {
            $meta['files'] = $this->outputs->collectCachedOutputFiles($destDir);
        }

        return $meta;
    }
}
