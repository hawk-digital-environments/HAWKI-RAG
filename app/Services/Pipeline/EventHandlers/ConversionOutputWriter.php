<?php

declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use App\Services\FileConverter\DocumentConverter;
use App\Services\Pipeline\Exceptions\PipelineEventHandlerException;
use Illuminate\Filesystem\Filesystem;
use Psr\Clock\ClockInterface;
use SplFileInfo;
use Symfony\Component\Clock\Clock;

class ConversionOutputWriter
{
    public function __construct(
        private readonly DocumentConverter $converter,
        private readonly Filesystem $files,
        private readonly ClockInterface $clock = new Clock,
    ) {}

    public function convert(array $event, string $path, string $contentHash): array
    {
        $file = new SplFileInfo($path);
        $outputDir = dirname($path).DIRECTORY_SEPARATOR.'converted_'.pathinfo($path, PATHINFO_FILENAME);
        $this->files->ensureDirectoryExists($outputDir);

        $files = $this->converter->requestDocumentToMarkdown($file);
        if (! is_array($files) || $files === []) {
            throw PipelineEventHandlerException::converterReturnedNoFiles();
        }

        $markdownFiles = [];
        foreach ($files as $relative => $content) {
            if (! is_string($content)) {
                continue;
            }

            $target = $outputDir.DIRECTORY_SEPARATOR.ltrim((string) $relative, DIRECTORY_SEPARATOR);
            $this->files->ensureDirectoryExists(dirname($target));
            $this->files->put($target, $content);

            if (str_ends_with(strtolower((string) $relative), '.md')) {
                $markdownFiles[(string) $relative] = $content;
            }
        }

        $markdownFiles = $this->sortByNaturalPath($markdownFiles);
        $markdownPath = $this->writeCombinedMarkdown($outputDir, $markdownFiles);
        if ($markdownPath === null) {
            $markdownPath = dirname($path).DIRECTORY_SEPARATOR.pathinfo($path, PATHINFO_FILENAME).'_converted.md';
            $this->files->put($markdownPath, $this->fallbackTextContent($files));
        }

        $this->writeMetadata($event, $path, $contentHash, $outputDir, $markdownPath, $files, $markdownFiles);

        return [
            'outputDir' => $outputDir,
            'markdownPath' => $markdownPath,
        ];
    }

    public function cachedConversion(string $path, string $contentHash): ?string
    {
        $outputDir = dirname($path).DIRECTORY_SEPARATOR.'converted_'.pathinfo($path, PATHINFO_FILENAME);
        $metaPath = $outputDir.DIRECTORY_SEPARATOR.'conversion_meta.json';
        if (! is_file($metaPath)) {
            return null;
        }

        $meta = json_decode((string) file_get_contents($metaPath), true);
        if (! is_array($meta) || (string) ($meta['converted_id'] ?? '') !== $contentHash) {
            return null;
        }

        $combined = (string) ($meta['combined_markdown_path'] ?? '');
        if ($combined !== '' && is_file($combined)) {
            return $combined;
        }

        foreach (($meta['files'] ?? []) as $relative) {
            $candidate = $outputDir.DIRECTORY_SEPARATOR.ltrim((string) $relative, DIRECTORY_SEPARATOR);
            if (is_file($candidate) && str_ends_with(strtolower($candidate), '.md')) {
                return $candidate;
            }
        }

        $flat = dirname($path).DIRECTORY_SEPARATOR.pathinfo($path, PATHINFO_FILENAME).'_converted.md';

        return is_file($flat) ? $flat : null;
    }

    /**
     * @param array<string, string> $markdownFiles
     */
    private function writeCombinedMarkdown(string $outputDir, array $markdownFiles): ?string
    {
        if ($markdownFiles === []) {
            return null;
        }

        $sections = [];
        foreach ($markdownFiles as $relative => $content) {
            if (trim($content) === '') {
                continue;
            }

            $sections[] = "<!-- converter-source: {$relative} -->\n\n".trim($content);
        }

        if ($sections === []) {
            return null;
        }

        $path = $outputDir.DIRECTORY_SEPARATOR.'content_markdown.md';
        $this->files->put($path, implode("\n\n---\n\n", $sections)."\n");

        return $path;
    }

    /**
     * @param array<string, string> $files
     * @return array<string, string>
     */
    private function sortByNaturalPath(array $files): array
    {
        uksort($files, 'strnatcasecmp');

        return $files;
    }

    /**
     * @param array<string, string> $files
     */
    private function fallbackTextContent(array $files): string
    {
        $textFiles = [];
        foreach ($files as $relative => $content) {
            if (! is_string($content) || trim($content) === '') {
                continue;
            }

            if (preg_match('/\.(txt|html?|csv|json|ya?ml|xml)$/i', (string) $relative) === 1) {
                $textFiles[(string) $relative] = $content;
            }
        }

        if ($textFiles === []) {
            return '';
        }

        uksort($textFiles, 'strnatcasecmp');

        return implode("\n\n---\n\n", array_map('trim', $textFiles))."\n";
    }

    private function writeMetadata(
        array $event,
        string $path,
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
            'source_file' => $path,
            'source_url' => $event['source_url'],
            'output_dir' => $outputDir,
            'files' => array_keys($files),
            'markdown_files' => array_keys($markdownFiles),
            'combined_markdown_path' => $markdownPath,
            'tool' => 'DocumentConverter',
            'version' => 'event-worker',
            'converted_at' => $this->clock->now()->format(\DateTimeInterface::ATOM),
        ];

        $this->files->put(
            $outputDir.DIRECTORY_SEPARATOR.'conversion_meta.json',
            json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }
}
