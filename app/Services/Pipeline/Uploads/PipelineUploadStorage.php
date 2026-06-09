<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Uploads;

use App\Services\Pipeline\Exceptions\PipelineUploadStorageException;
use App\Services\Pipeline\Values\PipelineStoredUpload;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

#[Singleton]
class PipelineUploadStorage
{
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
            File::deleteDirectory($taskRoot);

            throw PipelineUploadStorageException::moveFailed($taskRoot, $targetName, $exception);
        }

        $localPath = $taskRoot.DIRECTORY_SEPARATOR.$targetName;
        $contentHash = hash_file('sha256', $localPath) ?: hash('sha256', $localPath);

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
            File::ensureDirectoryExists($taskRoot);
        } catch (\Throwable $exception) {
            throw PipelineUploadStorageException::directoryNotWritable($taskRoot, $exception);
        }

        if (! is_dir($taskRoot) || ! is_writable($taskRoot)) {
            throw PipelineUploadStorageException::directoryNotWritable($taskRoot);
        }
    }

    private function taskRoot(string $taskId): string
    {
        return rtrim((string) config('communication.rabbitmq.pipeline_ingestion.shared_storage_root', '/app/shared'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .$taskId;
    }
}
