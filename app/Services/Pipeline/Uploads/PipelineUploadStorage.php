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
        return rtrim((string) $this->config->get('communication.rabbitmq.pipeline_ingestion.shared_storage_root', '/app/shared'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .$taskId;
    }
}
