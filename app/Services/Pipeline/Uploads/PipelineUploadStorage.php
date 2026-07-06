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
        $this->ensureStoredFileExists($file, $taskRoot, $targetName, $localPath);
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
        return rtrim((string) $this->config->get('temporal.storage.shared_root', '/shared'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .$taskId;
    }

    private function ensureStoredFileExists(UploadedFile $file, string $taskRoot, string $targetName, string $localPath): void
    {
        if ($this->files->exists($localPath)) {
            return;
        }

        $sourcePath = $file->getRealPath();
        if (! is_string($sourcePath) || $sourcePath === '' || ! $this->files->exists($sourcePath)) {
            $this->files->deleteDirectory($taskRoot);

            throw PipelineUploadStorageException::moveFailed(
                $taskRoot,
                $targetName,
                new \RuntimeException('Uploaded file was not readable after move().'),
            );
        }

        try {
            $this->files->copy($sourcePath, $localPath);
        } catch (\Throwable $exception) {
            $this->files->deleteDirectory($taskRoot);

            throw PipelineUploadStorageException::moveFailed($taskRoot, $targetName, $exception);
        }

        if (! $this->files->exists($localPath)) {
            $this->files->deleteDirectory($taskRoot);

            throw PipelineUploadStorageException::moveFailed(
                $taskRoot,
                $targetName,
                new \RuntimeException('Uploaded file copy fallback did not create the stored file.'),
            );
        }
    }
}
