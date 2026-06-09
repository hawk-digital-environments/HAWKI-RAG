<?php

declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class PipelineEventArtifactReader
{
    public function __construct(
        private Filesystem $files,
    ) {
    }

    public function isFile(string $path): bool
    {
        return $this->files->isFile($path);
    }

    public function isDirectory(string $path): bool
    {
        return $this->files->isDirectory($path);
    }

    public function readText(string $path): string
    {
        return (string) $this->files->get($path);
    }

    public function readJsonArray(string $path): ?array
    {
        if (! $this->isFile($path)) {
            return null;
        }

        try {
            $data = json_decode($this->readText($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($data) ? $data : null;
    }

    public function sha256(string $path, ?string $fallback = null): string
    {
        if ($this->isFile($path)) {
            $hash = $this->files->hash($path, 'sha256');
            if (is_string($hash) && $hash !== '') {
                return $hash;
            }
        }

        return $fallback ?? hash('sha256', $path);
    }

    public function size(string $path): ?int
    {
        if (! $this->isFile($path)) {
            return null;
        }

        try {
            $size = $this->files->size($path);
        } catch (\Throwable) {
            return null;
        }

        return $size > 0 ? $size : null;
    }
}
