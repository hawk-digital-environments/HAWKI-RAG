<?php

declare(strict_types=1);

namespace App\Services\Storage;

use App\Services\Storage\Exceptions\StorageReadException;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Filesystem\Filesystem;

#[Singleton]
readonly class StorageElementReader
{
    public function __construct(
        private Filesystem $filesystem,
        private StoragePathBuilder $paths,
        private UrlGenerator $urlGenerator,
    ) {
    }

    public function fetchElementContent(string $id, string $urlHash): string
    {
        return $this->filesystem->get($this->paths->folder($id, $urlHash).'/content.md');
    }

    public function fetchElementData(string $id, string $urlHash): array
    {
        $path = $this->paths->folder($id, $urlHash).'/data.json';

        if (! $this->filesystem->exists($path)) {
            throw StorageReadException::elementDataNotFound($path);
        }

        $json = $this->filesystem->get($path);
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw StorageReadException::invalidElementDataJson($path, json_last_error_msg());
        }

        return $data;
    }

    public function fetchImages(string $id, string $urlHash): array
    {
        $path = $this->paths->folder($id, $urlHash).'/images';

        if (! $this->filesystem->exists($path)) {
            return [];
        }

        return $this->filesystem->files($path);
    }

    public function getUrl(string $id, string $urlHash, string $name, ?string $type = null): ?string
    {
        $folder = $this->paths->folder($id, $urlHash);
        if ($type !== null) {
            $folder .= '/'.$type;
        }

        $path = $folder.'/'.$name;
        if (! $this->filesystem->exists($path)) {
            return null;
        }

        return $this->urlGenerator->generate($path);
    }
}
