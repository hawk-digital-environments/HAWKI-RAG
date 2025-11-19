<?php

namespace App\Services\Crawler\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Simplified storage manager for crawler operations.
 *
 * Uses any configured Laravel filesystem disk.
 */
class CrawlerStorageManager
{
    private Filesystem $disk;
    private string $diskName;
    private string $basePath;

    public function __construct()
    {
        $this->diskName = config('crawler.storage_disk', 'local');
        $this->basePath = config('crawler.storage_path', 'crawled-data');
        $this->disk = Storage::disk($this->diskName);
    }

    public function disk(): Filesystem
    {
        return $this->disk;
    }

    public function diskName(): string
    {
        return $this->diskName;
    }

    private function path(string $relativePath = ''): string
    {
        return $relativePath
            ? $this->basePath . '/' . $relativePath
            : $this->basePath;
    }

    public function exists(string $path): bool
    {
        return $this->disk->exists($this->path($path));
    }

    public function isDirectory(string $path): bool
    {
        return $this->disk->directoryExists($this->path($path));
    }

    public function directories(string $path = ''): array
    {
        $fullPath = $this->path($path);
        $directories = $this->disk->directories($fullPath);

        // Strip base path to return relative paths
        return array_map(function ($dir) {
            return str_replace($this->basePath . '/', '', $dir);
        }, $directories);
    }

    public function files(string $path = ''): array
    {
        $fullPath = $this->path($path);
        $files = $this->disk->files($fullPath);

        // Strip base path to return relative paths
        return array_map(function ($file) {
            return str_replace($this->basePath . '/', '', $file);
        }, $files);
    }

    public function get(string $path): string
    {
        return $this->disk->get($this->path($path));
    }

    public function put(string $path, string $contents): bool
    {
        return $this->disk->put($this->path($path), $contents);
    }

    public function delete(string $path): bool
    {
        if ($this->isDirectory($path)) {
            return $this->disk->deleteDirectory($this->path($path));
        }

        return $this->disk->delete($this->path($path));
    }

    public function deleteDirectory(string $path): bool
    {
        return $this->disk->deleteDirectory($this->path($path));
    }

    public function makeDirectory(string $path): bool
    {
        return $this->disk->makeDirectory($this->path($path));
    }

    public function size(string $path): int
    {
        return $this->disk->size($this->path($path));
    }

    public function lastModified(string $path): int
    {
        return $this->disk->lastModified($this->path($path));
    }

    public function labelPath(string $label): string
    {
        return $label;
    }

    public function directoryPath(string $label, int $number): string
    {
        return $label . '/' . str_pad($number, 5, '0', STR_PAD_LEFT);
    }

    public function dataFilePath(string $label, int $number): string
    {
        $paddedNumber = str_pad($number, 5, '0', STR_PAD_LEFT);
        return $this->directoryPath($label, $number) . "/data_{$paddedNumber}.json";
    }

    public function siteFilePath(string $label, int $number): string
    {
        $paddedNumber = str_pad($number, 5, '0', STR_PAD_LEFT);
        return $this->directoryPath($label, $number) . "/site_{$paddedNumber}.txt";
    }

    public function progressFilePath(string $label): string
    {
        return "../crawler-progress-{$label}.json";
    }

    public function getNumberedDirectories(string $label): array
    {
        if (!$this->isDirectory($label)) {
            return [];
        }

        $directories = $this->directories($label);

        return collect($directories)
            ->map(fn($path) => basename($path))
            ->filter(fn($name) => preg_match('/^\d{5}$/', $name))
            ->map(fn($name) => (int) $name)
            ->sort()
            ->values()
            ->toArray();
    }

    public function directorySize(string $path): int
    {
        if (!$this->isDirectory($path)) {
            return 0;
        }

        $fullPath = $this->path($path);
        $files = $this->disk->allFiles($fullPath);
        $size = 0;

        foreach ($files as $file) {
            $size += $this->disk->size($file);
        }

        return $size;
    }

    public function getTempDirectories(): array
    {
        $directories = $this->directories();

        return collect($directories)
            ->filter(fn($path) => preg_match('/temp-\d+$/', basename($path)))
            ->values()
            ->toArray();
    }

    public function isDirectoryComplete(string $label, int $number): bool
    {
        $dataFile = $this->dataFilePath($label, $number);
        $siteFile = $this->siteFilePath($label, $number);

        if (!$this->exists($dataFile) || !$this->exists($siteFile)) {
            return false;
        }

        if ($this->size($dataFile) === 0 || $this->size($siteFile) === 0) {
            return false;
        }

        try {
            $data = json_decode($this->get($dataFile), true);
            if (empty($data['title']) || empty($data['page_url'])) {
                return false;
            }
        } catch (\Throwable $e) {
            return false;
        }

        return true;
    }
}
