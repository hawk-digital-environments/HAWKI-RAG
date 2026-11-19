<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Services\Document\Values\UploadedSourceDocument;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class UploadedSourceDocumentService
{
    public function __construct(
        private UploadedSourceDocumentRepository $repository,
        private ConfigRepository $config,
    ) {}

    public function resolve(string $sourceUrl, ?string $contentHash = null): ?UploadedSourceDocument
    {
        $sourceUrl = $this->uploadSourceUrl($sourceUrl);
        if ($sourceUrl === null) {
            return null;
        }

        $contentHash = $this->contentHash($contentHash);

        foreach ($this->pipelineJobs($sourceUrl, $contentHash) as $job) {
            $document = $this->fromPipelineJob($sourceUrl, $job);
            if ($document !== null) {
                return $document;
            }
        }

        foreach ($this->ingestionSources($sourceUrl, $contentHash) as $source) {
            $document = $this->fromIngestionSource($sourceUrl, $source);
            if ($document !== null) {
                return $document;
            }
        }

        if ($this->config->get('config.managed_document_backend', 'python') !== 'laravel'
            || $this->config->get('config.crawler_ingestion_backend', 'python') !== 'laravel') {
            return null; // Python defaults require authoritative identity, never filename recovery.
        }

        return $this->fromSharedUploadStorage($sourceUrl, $contentHash);
    }

    /** $document must come from Python's authorized document read, never request data. */
    public function resolvePython(string $sourceUrl, ?string $contentHash, array $document): ?UploadedSourceDocument
    {
        $upload = $document['upload'] ?? null;
        // The original name is a matching/display value. Only the persisted
        // relative path below selects a file or crosses the filesystem boundary.
        if (! str_starts_with($sourceUrl, 'upload://') || ! is_array($upload)
            || ($document['source_type'] ?? null) !== 'upload' || ($document['status'] ?? null) === 'deleted') {
            return null;
        }
        foreach (['task_id', 'source_id', 'relative_path', 'filename', 'sha256'] as $field) {
            if (! is_string($upload[$field] ?? null) || $upload[$field] === '') {
                return null;
            }
        }
        $task = $upload['task_id'];
        $relative = $upload['relative_path'];
        $hash = $upload['sha256'];
        if (preg_match('/\Atask_managed_[a-f0-9]{32}\z/', $task) !== 1
            || preg_match('/\Asource_[a-f0-9]{32}\z/', $upload['source_id']) !== 1
            || ($document['latest_task_id'] ?? null) !== $task
            || ($document['latest_source_id'] ?? null) !== $upload['source_id']
            || preg_match('/\A[a-f0-9]{64}\z/', $hash) !== 1
            || ! str_starts_with($relative, $task.'/')
            || str_contains($relative, '..') || strpbrk($relative, "\\\0\r\n*?[]{}") !== false
            || substr_count($relative, '/') !== 1 || basename($relative) === ''
            || ($sourceUrl !== 'upload://'.$upload['filename'] && rawurldecode(substr($sourceUrl, strlen('upload://'))) !== $upload['filename'])
            || ($contentHash !== null && strtolower(trim($contentHash)) !== $hash)) {
            return null;
        }

        foreach ($this->allowedRoots() as $root) {
            $realRoot = realpath($root);
            if ($realRoot === false || ! is_dir($realRoot)) {
                continue;
            }
            $expected = rtrim($realRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$relative;
            $path = realpath($expected);
            // Exact equality rejects file and directory symlinks, including
            // links to another task inside the otherwise allowed shared root.
            if ($path === $expected && is_file($path) && is_readable($path)
                && $this->pathContentHash($path) === $hash) {
                return new UploadedSourceDocument($path, $upload['filename']);
            }
        }

        return null;
    }

    /**
     * @return iterable<int, PipelineJob>
     */
    private function pipelineJobs(string $sourceUrl, ?string $contentHash): iterable
    {
        $matches = $this->repository->pipelineJobs($sourceUrl, $contentHash);

        return $matches->isNotEmpty() || $contentHash === null
            ? $matches
            : $this->repository->pipelineJobs($sourceUrl);
    }

    /**
     * @return iterable<int, IngestionSource>
     */
    private function ingestionSources(string $sourceUrl, ?string $contentHash): iterable
    {
        $matches = $this->repository->ingestionSources($sourceUrl, $contentHash);

        return $matches->isNotEmpty() || $contentHash === null
            ? $matches
            : $this->repository->ingestionSources($sourceUrl);
    }

    private function fromPipelineJob(string $sourceUrl, PipelineJob $job): ?UploadedSourceDocument
    {
        $paths = [
            $job->local_path,
            data_get($job->metadata, 'upload.local_path'),
        ];
        $downloadName = $this->downloadName($sourceUrl, data_get($job->metadata, 'upload.original_filename'));

        return $this->firstReadableDocument($paths, $downloadName);
    }

    private function fromIngestionSource(string $sourceUrl, IngestionSource $source): ?UploadedSourceDocument
    {
        $paths = [
            data_get($source->metadata, 'upload.local_path'),
        ];
        $downloadName = $this->downloadName($sourceUrl, data_get($source->metadata, 'upload.original_filename'));

        return $this->firstReadableDocument($paths, $downloadName);
    }

    private function fromSharedUploadStorage(string $sourceUrl, ?string $contentHash): ?UploadedSourceDocument
    {
        $downloadName = $this->downloadName($sourceUrl);
        $fallbacks = [];

        foreach ($this->sharedUploadCandidatePaths() as $path) {
            $safePath = $this->safeReadablePath($path);
            if ($safePath === null || ! $this->pathMatchesUploadName($safePath, $downloadName)) {
                continue;
            }

            if ($contentHash !== null && $this->pathContentHash($safePath) === $contentHash) {
                return new UploadedSourceDocument($safePath, $downloadName);
            }

            $fallbacks[$safePath] = filemtime($safePath) ?: 0;
        }

        if ($fallbacks === []) {
            return null;
        }

        arsort($fallbacks);

        return new UploadedSourceDocument((string) array_key_first($fallbacks), $downloadName);
    }

    /**
     * @return \Generator<int, string>
     */
    private function sharedUploadCandidatePaths(): \Generator
    {
        $patterns = [
            'task_controller_upload_*/*',
            'sources/*/raw/*',
        ];

        foreach ($this->allowedRoots() as $root) {
            foreach ($patterns as $pattern) {
                foreach (glob(rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$pattern, GLOB_NOSORT) ?: [] as $path) {
                    yield $path;
                }
            }
        }
    }

    private function pathMatchesUploadName(string $path, string $downloadName): bool
    {
        $requested = $this->normalizedFileParts($downloadName);
        $candidate = $this->normalizedFileParts(basename($path));

        if ($requested['extension'] !== '' && $candidate['extension'] !== $requested['extension']) {
            return false;
        }

        return $candidate['stem'] === $requested['stem']
            || str_starts_with($candidate['stem'], $requested['stem'].'-');
    }

    /**
     * @return array{stem: string, extension: string}
     */
    private function normalizedFileParts(string $name): array
    {
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $stem = (string) pathinfo($name, PATHINFO_FILENAME);
        $stem = strtolower($stem);
        $stem = preg_replace('/[^a-z0-9]+/', '-', $stem) ?? $stem;
        $stem = trim($stem, '-');

        return [
            'stem' => $stem,
            'extension' => $extension,
        ];
    }

    private function pathContentHash(string $path): ?string
    {
        $hash = hash_file('sha256', $path);

        return is_string($hash) && $hash !== '' ? strtolower($hash) : null;
    }

    /**
     * @param  list<mixed>  $paths
     */
    private function firstReadableDocument(array $paths, string $downloadName): ?UploadedSourceDocument
    {
        foreach ($paths as $path) {
            $safePath = $this->safeReadablePath($path);
            if ($safePath !== null) {
                return new UploadedSourceDocument($safePath, $downloadName);
            }
        }

        return null;
    }

    private function safeReadablePath(mixed $path): ?string
    {
        if (! is_scalar($path)) {
            return null;
        }

        $path = trim((string) $path);
        if ($path === '' || str_starts_with($path, 's3://')) {
            return null;
        }

        $path = preg_replace('/^file:\/\//i', '', $path) ?? $path;
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = base_path($path);
        }

        $realPath = realpath($path);
        if ($realPath === false || ! is_file($realPath) || ! is_readable($realPath)) {
            return null;
        }

        return $this->pathIsInsideAllowedRoot($realPath) && ! $this->isPythonManagedPath($realPath) ? $realPath : null;
    }

    /** Legacy rows and scans cannot bypass Python document authorization. */
    private function isPythonManagedPath(string $path): bool
    {
        foreach ($this->allowedRoots() as $root) {
            $realRoot = realpath($root);
            if ($realRoot !== false && preg_match('#\A'.preg_quote(rtrim($realRoot, DIRECTORY_SEPARATOR), '#').'/task_managed_[a-f0-9]{32}/#', $path) === 1) {
                return true;
            }
        }

        return false;
    }

    private function pathIsInsideAllowedRoot(string $path): bool
    {
        foreach ($this->allowedRoots() as $root) {
            $realRoot = realpath($root);
            if ($realRoot === false || ! is_dir($realRoot)) {
                continue;
            }

            $prefix = rtrim($realRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            if ($path === $realRoot || str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function allowedRoots(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $root): string => rtrim(trim((string) $root), DIRECTORY_SEPARATOR),
            [
                $this->config->get('temporal.storage.shared_root'),
                $this->config->get('config.pipeline_root'),
                $this->config->get('config.shared_root'),
                $this->config->get('config.crawled_data_root'),
                '/shared',
            ],
        ), static fn (string $root): bool => $root !== '')));
    }

    private function uploadSourceUrl(string $sourceUrl): ?string
    {
        $sourceUrl = trim($sourceUrl);
        if ($sourceUrl === '' || ! str_starts_with(strtolower($sourceUrl), 'upload://')) {
            return null;
        }
        $name = rawurldecode(substr($sourceUrl, strlen('upload://')));
        if ($name === '' || str_contains($name, '..') || strpbrk($name, "/\\\0\r\n*?[]{}") !== false) {
            return null;
        }

        return $sourceUrl;
    }

    private function contentHash(?string $contentHash): ?string
    {
        $contentHash = is_string($contentHash) ? strtolower(trim($contentHash)) : '';

        return preg_match('/^[a-f0-9]{32,128}$/', $contentHash) === 1 ? $contentHash : null;
    }

    private function downloadName(string $sourceUrl, mixed $preferredName = null): string
    {
        $name = is_scalar($preferredName) && trim((string) $preferredName) !== ''
            ? (string) $preferredName
            : rawurldecode(substr($sourceUrl, strlen('upload://')));

        $name = basename(str_replace('\\', '/', str_replace(["\0", "\r", "\n"], '', $name)));

        return $name !== '' ? $name : 'uploaded-document';
    }
}
