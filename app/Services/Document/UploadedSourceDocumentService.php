<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\Document;
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
    ) {
    }

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

        foreach ($this->documents($sourceUrl, $contentHash) as $documentModel) {
            $document = $this->fromDocument($sourceUrl, $documentModel);
            if ($document !== null) {
                return $document;
            }
        }

        return $this->fromSharedUploadStorage($sourceUrl, $contentHash);
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

    /**
     * @return iterable<int, Document>
     */
    private function documents(string $sourceUrl, ?string $contentHash): iterable
    {
        $matches = $this->repository->documents($sourceUrl, $contentHash);

        return $matches->isNotEmpty() || $contentHash === null
            ? $matches
            : $this->repository->documents($sourceUrl);
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

    private function fromDocument(string $sourceUrl, Document $document): ?UploadedSourceDocument
    {
        $paths = [
            $document->storage_path,
            data_get($document->metadata_json, 'upload.local_path'),
            data_get($document->metadata_json, 'passthrough.original_path'),
            data_get($document->metadata_json, 'passthrough.file_path'),
        ];
        $downloadName = $this->downloadName($sourceUrl, $document->original_filename);

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
     * @param list<mixed> $paths
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

        return $this->pathIsInsideAllowedRoot($realPath) ? $realPath : null;
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
