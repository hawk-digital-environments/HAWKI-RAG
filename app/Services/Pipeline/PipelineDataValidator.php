<?php

namespace App\Services\Pipeline;

class PipelineDataValidator
{
    /**
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function validateScrapeElement(array $data): array
    {
        $errors = [];
        $warnings = [];

        $pageUrl = $this->firstScalar($data['page_url'] ?? null);
        $title = $this->firstScalar($data['title'] ?? null);
        $urlHash = $this->firstScalar($data['url_hash'] ?? null);

        if ($pageUrl === null || !filter_var($pageUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'page_url is missing or invalid.';
        }

        if ($urlHash === null) {
            $errors[] = 'url_hash is missing.';
        }

        if ($title === null) {
            $warnings[] = 'title is missing.';
        }

        if (!array_key_exists('content_hash', $data) || $this->firstScalar($data['content_hash']) === null) {
            $warnings[] = 'content_hash is missing.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function validateConvertedFiles(array $files): array
    {
        $errors = [];
        $warnings = [];
        $markdownFiles = 0;

        if ($files === []) {
            $errors[] = 'converter returned no files.';
        }

        foreach ($files as $relative => $content) {
            if (!is_string($relative) || trim($relative) === '') {
                $errors[] = 'converter returned a file with an empty relative path.';
                continue;
            }

            if ($this->pathLeavesRoot($relative)) {
                $errors[] = "converter returned unsafe relative path: {$relative}";
                continue;
            }

            if (str_ends_with(strtolower($relative), '.md')) {
                $markdownFiles++;
                $markdown = $this->validateMarkdownContent(is_string($content) ? $content : null);
                foreach ($markdown['errors'] as $error) {
                    $errors[] = "{$relative}: {$error}";
                }
            }
        }

        if ($markdownFiles === 0) {
            $errors[] = 'converter output does not contain a Markdown file.';
        }

        return ['errors' => array_values(array_unique($errors)), 'warnings' => $warnings];
    }

    /**
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function validateMarkdownContent(?string $content): array
    {
        $errors = [];
        $warnings = [];

        if ($content === null) {
            $errors[] = 'Markdown content is not readable.';
            return ['errors' => $errors, 'warnings' => $warnings];
        }

        $trimmed = trim($content);
        if ($trimmed === '') {
            $errors[] = 'Markdown content is empty.';
        }

        if ($trimmed !== '' && strlen($trimmed) < 20) {
            $warnings[] = 'Markdown content is very short.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function validateConversionMetadata(array $metadata): array
    {
        $errors = [];
        $warnings = [];

        foreach (['converted_id', 'source_file', 'output_dir', 'files', 'converted_at'] as $field) {
            if (!array_key_exists($field, $metadata) || $metadata[$field] === null || $metadata[$field] === '') {
                $errors[] = "{$field} is missing.";
            }
        }

        if (isset($metadata['files']) && !is_array($metadata['files'])) {
            $errors[] = 'files must be an array.';
        }

        if (isset($metadata['source_file']) && is_string($metadata['source_file']) && !is_file($metadata['source_file'])) {
            $warnings[] = 'source_file does not exist on disk.';
        }

        if (!array_key_exists('doc_id', $metadata) || $metadata['doc_id'] === null || $metadata['doc_id'] === '') {
            $warnings[] = 'doc_id is missing.';
        }

        if (!array_key_exists('title', $metadata) || $metadata['title'] === null || trim((string) $metadata['title']) === '') {
            $warnings[] = 'title is missing.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    public function firstScalar(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function pathLeavesRoot(string $relative): bool
    {
        $normalized = str_replace('\\', '/', $relative);

        return str_starts_with($normalized, '/')
            || str_contains($normalized, '../')
            || $normalized === '..'
            || str_starts_with($normalized, '..');
    }
}
