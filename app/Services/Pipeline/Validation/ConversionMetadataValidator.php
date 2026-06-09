<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Validation;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class ConversionMetadataValidator
{
    public function __construct(
        private MarkdownContentValidator $markdown,
        private PipelinePathSafetyValidator $paths,
        private Filesystem $files,
    ) {
    }

    /**
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function validate(array $metadata): array
    {
        $errors = [];
        $warnings = [];

        foreach (['converted_id', 'source_file', 'output_dir', 'files', 'converted_at'] as $field) {
            if (! array_key_exists($field, $metadata) || $metadata[$field] === null || $metadata[$field] === '') {
                $errors[] = "{$field} is missing.";
            }
        }

        if (isset($metadata['files']) && ! is_array($metadata['files'])) {
            $errors[] = 'files must be an array.';
        }

        if (isset($metadata['files']) && is_array($metadata['files'])) {
            $this->validateListedFiles($metadata, $errors, $warnings);
        }

        if (isset($metadata['source_file']) && is_string($metadata['source_file']) && ! $this->files->isFile($metadata['source_file'])) {
            $warnings[] = 'source_file does not exist on disk.';
        }

        if (! array_key_exists('doc_id', $metadata) || $metadata['doc_id'] === null || $metadata['doc_id'] === '') {
            $warnings[] = 'doc_id is missing.';
        }

        if (! array_key_exists('title', $metadata) || $metadata['title'] === null || trim((string) $metadata['title']) === '') {
            $warnings[] = 'title is missing.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private function validateListedFiles(array $metadata, array &$errors, array &$warnings): void
    {
        $markdownFiles = 0;
        $outputDir = isset($metadata['output_dir']) && is_string($metadata['output_dir'])
            ? rtrim($metadata['output_dir'], DIRECTORY_SEPARATOR)
            : null;

        foreach ($metadata['files'] as $file) {
            if (! is_string($file) || trim($file) === '') {
                $errors[] = 'files contains an empty or invalid path.';

                continue;
            }

            if ($this->paths->leavesRoot($file)) {
                $errors[] = "files contains unsafe relative path: {$file}";

                continue;
            }

            if (str_ends_with(strtolower($file), '.md')) {
                $markdownFiles++;
                $this->validateMarkdownFile($file, $outputDir, $errors, $warnings);
            }
        }

        if ($markdownFiles === 0) {
            $errors[] = 'files must include at least one Markdown file.';
        }
    }

    private function validateMarkdownFile(string $file, ?string $outputDir, array &$errors, array &$warnings): void
    {
        if ($outputDir === null || ! $this->files->isDirectory($outputDir)) {
            return;
        }

        $path = $outputDir.DIRECTORY_SEPARATOR.ltrim($file, DIRECTORY_SEPARATOR);
        if (! $this->files->isFile($path)) {
            $errors[] = "Markdown file listed in metadata is missing: {$file}";

            return;
        }

        try {
            $content = $this->files->get($path);
        } catch (\Throwable) {
            $content = null;
        }

        $markdown = $this->markdown->validate(is_string($content) ? $content : null);
        foreach ($markdown['errors'] as $error) {
            $errors[] = "{$file}: {$error}";
        }
        foreach ($markdown['warnings'] as $warning) {
            $warnings[] = "{$file}: {$warning}";
        }
    }
}
