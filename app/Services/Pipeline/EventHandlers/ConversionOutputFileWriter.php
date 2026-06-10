<?php

declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class ConversionOutputFileWriter
{
    public function __construct(
        private Filesystem $files,
        private ConversionOutputPaths $paths,
    ) {
    }

    /**
     * @param array<array-key, mixed> $convertedFiles
     * @return array{markdownPath: string, markdownFiles: array<string, string>}
     */
    public function write(string $sourcePath, string $outputDir, array $convertedFiles): array
    {
        $markdownFiles = [];

        foreach ($convertedFiles as $relative => $content) {
            if (! is_string($content)) {
                continue;
            }

            $relativePath = (string) $relative;
            $target = $outputDir.DIRECTORY_SEPARATOR.ltrim($relativePath, DIRECTORY_SEPARATOR);
            $this->files->ensureDirectoryExists(dirname($target));
            $this->files->put($target, $content);

            if (str_ends_with(strtolower($relativePath), '.md')) {
                $markdownFiles[$relativePath] = $content;
            }
        }

        $markdownFiles = $this->sortByNaturalPath($markdownFiles);
        $markdownPath = $this->writeCombinedMarkdown($outputDir, $markdownFiles);
        if ($markdownPath === null) {
            $markdownPath = $this->paths->flatMarkdownPath($sourcePath);
            $this->files->put($markdownPath, $this->fallbackTextContent($convertedFiles));
        }

        return [
            'markdownPath' => $markdownPath,
            'markdownFiles' => $markdownFiles,
        ];
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
     * @param array<array-key, mixed> $files
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
}
