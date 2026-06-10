<?php

declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class ConversionOutputMetadataWriter
{
    public function __construct(
        private Filesystem $files,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * @param array<string, mixed> $event
     * @param array<array-key, mixed> $files
     * @param array<string, string> $markdownFiles
     */
    public function write(
        array $event,
        string $sourcePath,
        string $contentHash,
        string $outputDir,
        string $markdownPath,
        array $files,
        array $markdownFiles,
    ): void {
        $metadata = [
            'pipeline_job_id' => $event['parent_job_id'] ?: $event['job_id'],
            'task_id' => $event['task_id'],
            'conversion_job_id' => $event['job_id'],
            'converted_id' => $contentHash,
            'doc_id' => $contentHash,
            'source_file' => $sourcePath,
            'source_url' => $event['source_url'],
            'output_dir' => $outputDir,
            'files' => array_map('strval', array_keys($files)),
            'markdown_files' => array_keys($markdownFiles),
            'combined_markdown_path' => $markdownPath,
            'tool' => 'DocumentConverter',
            'version' => 'event-worker',
            'converted_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
        ];

        $this->files->put(
            $outputDir.DIRECTORY_SEPARATOR.'conversion_meta.json',
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
