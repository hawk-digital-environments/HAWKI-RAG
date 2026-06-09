<?php

declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class IngestionContentResolver
{
    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Filesystem $files,
    ) {}

    public function contentPaths(array $event): array
    {
        $path = $this->resolvePath((string) ($event['local_path'] ?? ''));
        if ($path && is_file($path) && $this->isTextLike($path)) {
            return [$path];
        }

        if ($path && is_dir($path)) {
            $paths = [];
            foreach ($this->files->allFiles($path) as $file) {
                if ($this->isTextLike($file->getPathname())) {
                    $paths[] = $file->getPathname();
                }
            }

            return $paths;
        }

        return [];
    }

    public function resolvePath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        if (Str::startsWith($path, ['/', '\\'])) {
            return realpath($path) ?: $path;
        }

        $candidate = rtrim((string) $this->config->get('communication.rabbitmq.pipeline_ingestion.shared_storage_root', '/app/shared'), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR
            .ltrim($path, DIRECTORY_SEPARATOR);

        return realpath($candidate) ?: $candidate;
    }

    private function isTextLike(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['md', 'txt', 'html'], true);
    }
}
