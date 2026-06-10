<?php

declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use App\Services\FileConverter\DocumentConverter;
use App\Services\Pipeline\Exceptions\PipelineEventHandlerException;
use Illuminate\Filesystem\Filesystem;

class ConversionOutputWriter
{
    public function __construct(
        private readonly DocumentConverter $converter,
        private readonly Filesystem $files,
        private readonly ConversionOutputPaths $paths,
        private readonly ConversionOutputFileWriter $fileWriter,
        private readonly ConversionOutputMetadataWriter $metadata,
        private readonly ConversionOutputCache $cache,
    ) {}

    public function convert(array $event, string $path, string $contentHash): array
    {
        $file = new \SplFileInfo($path);
        $outputDir = $this->paths->outputDirectory($path);
        $this->files->ensureDirectoryExists($outputDir);

        $files = $this->converter->requestDocumentToMarkdown($file);
        if (! is_array($files) || $files === []) {
            throw PipelineEventHandlerException::converterReturnedNoFiles();
        }

        $written = $this->fileWriter->write($path, $outputDir, $files);
        $this->metadata->write($event, $path, $contentHash, $outputDir, $written['markdownPath'], $files, $written['markdownFiles']);

        return [
            'outputDir' => $outputDir,
            'markdownPath' => $written['markdownPath'],
        ];
    }

    public function cachedConversion(string $path, string $contentHash): ?string
    {
        return $this->cache->cachedConversion($path, $contentHash);
    }
}
