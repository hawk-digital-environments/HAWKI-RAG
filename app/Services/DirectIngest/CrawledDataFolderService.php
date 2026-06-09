<?php

declare(strict_types=1);

namespace App\Services\DirectIngest;

use App\Services\DirectIngest\Values\DirectIngestActionResult;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\File;

#[Singleton]
readonly class CrawledDataFolderService
{
    public function list(): DirectIngestActionResult
    {
        $root = $this->root();
        if (! $root) {
            return DirectIngestActionResult::fromPayload([
                'ok' => false,
                'message' => 'Crawled-data root not found.',
            ], 404);
        }

        return DirectIngestActionResult::fromPayload([
            'ok' => true,
            'root' => $root,
            'folders' => $this->folderList($root),
        ]);
    }

    public function delete(string $path): DirectIngestActionResult
    {
        $root = $this->root();
        if (! $root) {
            return DirectIngestActionResult::fromPayload([
                'ok' => false,
                'message' => 'Crawled-data root not found.',
            ], 404);
        }

        if (! is_dir($path)) {
            return DirectIngestActionResult::fromPayload(['ok' => false, 'message' => "Folder not found: {$path}"], 404);
        }
        if (! $this->isPathWithinRoot($path, $root)) {
            return DirectIngestActionResult::fromPayload(['ok' => false, 'message' => 'Path must be within the crawled-data root.'], 422);
        }

        $resolvedPath = realpath($path);
        if ($resolvedPath === false) {
            return DirectIngestActionResult::fromPayload(['ok' => false, 'message' => 'Unable to resolve folder path.'], 422);
        }

        try {
            $deleted = File::deleteDirectory($resolvedPath);
        } catch (\Throwable) {
            return DirectIngestActionResult::fromPayload(['ok' => false, 'message' => 'Failed to delete folder.'], 500);
        }

        if (! $deleted || is_dir($resolvedPath)) {
            $perms = @fileperms($resolvedPath);
            $permText = $perms ? substr(sprintf('%o', $perms), -4) : 'unknown';

            return DirectIngestActionResult::fromPayload([
                'ok' => false,
                'message' => 'Delete failed. Check folder permissions/ownership.',
                'path' => $resolvedPath,
                'writable' => is_writable($resolvedPath),
                'permissions' => $permText,
            ], 500);
        }

        return DirectIngestActionResult::fromPayload([
            'ok' => true,
            'deleted' => $resolvedPath,
            'folders' => $this->folderList($root),
            'root' => $root,
        ]);
    }

    public function root(): ?string
    {
        $root = (string) config('config.crawled_data_root', '/app/shared');
        if (is_dir($root)) {
            return realpath($root) ?: $root;
        }

        return null;
    }

    public function isPathWithinRoot(string $path, string $root): bool
    {
        $resolvedPath = realpath($path);
        $resolvedRoot = realpath($root);

        if ($resolvedPath === false || $resolvedRoot === false) {
            return false;
        }

        return $resolvedPath === $resolvedRoot
            || str_starts_with($resolvedPath, $resolvedRoot.DIRECTORY_SEPARATOR);
    }

    private function folderList(string $root): array
    {
        $dirs = File::directories($root);
        $folders = [];
        foreach ($dirs as $dir) {
            $name = basename($dir);
            if (str_starts_with($name, '.') || preg_match('/^sitemaps?$/i', $name)) {
                continue;
            }

            $folders[] = [
                'name' => $name,
                'path' => $dir,
            ];
        }

        usort($folders, static fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $folders;
    }
}
