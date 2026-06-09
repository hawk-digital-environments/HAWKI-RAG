<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class ConversionMetadataFactory
{
    public function __construct(
        private Filesystem $files,
        private ClockInterface $clock = new Clock,
    ) {
    }

    /**
     * @param list<string> $written
     * @return array<string, mixed>
     */
    public function make(
        string $jobId,
        string $convertedId,
        string $docTitle,
        string $docPath,
        string $outputDir,
        array $written,
    ): array {
        return [
            'pipeline_job_id' => $jobId,
            'converted_id' => $convertedId,
            'doc_id' => $convertedId,
            'title' => $docTitle,
            'source_pdf' => $docPath,
            'source_file' => $docPath,
            'source_size' => $this->sourceSize($docPath),
            'source_mtime' => $this->sourceModifiedAt($docPath),
            'output_dir' => $outputDir,
            'files' => $written,
            'converted_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
            'tool' => 'DocumentConverter::requestDocumentToMarkdown',
            'version' => 1,
        ];
    }

    private function sourceSize(string $docPath): ?int
    {
        if (! $this->files->isFile($docPath)) {
            return null;
        }

        return $this->files->size($docPath);
    }

    private function sourceModifiedAt(string $docPath): ?string
    {
        if (! $this->files->isFile($docPath)) {
            return null;
        }

        return date('c', $this->files->lastModified($docPath));
    }
}
