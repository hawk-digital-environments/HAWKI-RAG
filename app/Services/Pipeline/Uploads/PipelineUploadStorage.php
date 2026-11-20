<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Uploads;

use App\Services\Pipeline\Exceptions\PipelineUploadStorageException;
use App\Services\Pipeline\PipelineFileHasher;
use App\Services\Pipeline\Values\PipelineStoredUpload;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

#[Singleton]
class PipelineUploadStorage
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Filesystem $files,
        private readonly PipelineFileHasher $hasher,
    ) {}

    public function extensionFor(UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: '');
    }

    public function store(string $taskId, UploadedFile $file, string $extension): PipelineStoredUpload
    {
        $taskRoot = $this->taskRoot($taskId);
        $this->prepareTaskRoot($taskRoot);

        $originalName = $file->getClientOriginalName() ?: "uploaded.{$extension}";
        $baseName = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'uploaded-document';
        $targetName = $baseName.'-'.Str::lower(Str::random(8)).'.'.$extension;

        try {
            $file->move($taskRoot, $targetName);
        } catch (\Throwable $exception) {
            $this->files->deleteDirectory($taskRoot);

            throw PipelineUploadStorageException::moveFailed($taskRoot, $targetName, $exception);
        }

        $localPath = $taskRoot.DIRECTORY_SEPARATOR.$targetName;
        $contentHash = $this->hasher->sha256($localPath);

        return PipelineStoredUpload::fromStoredFile(
            $originalName,
            $targetName,
            $localPath,
            $contentHash,
            $extension,
        );
    }

    private function prepareTaskRoot(string $taskRoot): void
    {
        try {
            $this->files->ensureDirectoryExists($taskRoot);
        } catch (\Throwable $exception) {
            throw PipelineUploadStorageException::directoryNotWritable($taskRoot, $exception);
        }

        if (! $this->files->isDirectory($taskRoot) || ! is_writable($taskRoot)) {
            throw PipelineUploadStorageException::directoryNotWritable($taskRoot);
        }
    }

    private function taskRoot(string $taskId): string
    {
        return $this->sharedRoot()
            .DIRECTORY_SEPARATOR
            .$taskId;
    }

    private function sharedRoot(): string
    {
        $root = trim((string) $this->config->get('temporal.storage.shared_root', '/shared'));
        $normalized = rtrim($root, DIRECTORY_SEPARATOR);
        if (
            $normalized === ''
            || ! str_starts_with($normalized, DIRECTORY_SEPARATOR)
            || preg_match('#(^|/)\.{1,2}(/|$)#', $normalized) === 1
        ) {
            throw new \LogicException(
                'temporal.storage.shared_root must be a canonical absolute directory below the filesystem root.',
            );
        }

        return $normalized;
    }
}
