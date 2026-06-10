<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Services\Storage\Exceptions\StorageReadException;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Filesystem\Filesystem;

#[Singleton]
readonly class StorageJobReportReader
{
    public function __construct(
        private Filesystem $filesystem,
        private StoragePathBuilder $paths,
    ) {
    }

    public function fetchJobReport(string $id, string $type): array
    {
        $path = $this->paths->folder($id).'/'.$type.'.json';

        if (! $this->filesystem->exists($path)) {
            throw StorageReadException::jobReportNotFound($path);
        }

        $json = $this->filesystem->get($path);

        if (! $json) {
            throw StorageReadException::jobReportUnreadable($path);
        }

        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw StorageReadException::invalidJobReportJson($path, json_last_error_msg());
        }

        return $data;
    }

    public function fetchUrlsList(string $id): array
    {
        $folder = $this->paths->folder($id).'/url_chunks';

        if (! $this->filesystem->exists($folder)) {
            throw StorageReadException::urlChunksFolderNotFound($folder);
        }

        $files = $this->filesystem->files($folder);

        if ($files === []) {
            throw StorageReadException::urlChunksEmpty($folder);
        }

        $urls = [];
        foreach ($files as $file) {
            $json = $this->filesystem->get($file);
            $data = json_decode($json, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw StorageReadException::invalidUrlChunkJson((string) $file, json_last_error_msg());
            }

            foreach ($data as $urlData) {
                $urls[] = $urlData;
            }
        }

        return $urls;
    }
}
