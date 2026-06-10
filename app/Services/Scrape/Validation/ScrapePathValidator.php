<?php

declare(strict_types=1);

namespace App\Services\Scrape\Validation;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class ScrapePathValidator
{
    public function __construct(private Filesystem $files)
    {
    }

    public function isValidUrlOrFile(string $urlOrPath): bool
    {
        if ($this->files->exists($urlOrPath) && $this->files->isReadable($urlOrPath)) {
            return true;
        }

        return filter_var($urlOrPath, FILTER_VALIDATE_URL) !== false;
    }

    public function isValidDirectory(string $directory): bool
    {
        if ($this->files->exists($directory)) {
            if (! $this->files->isDirectory($directory)) {
                return false;
            }

            return $this->files->isWritable($directory);
        }

        $parent = dirname($directory);
        if ($parent === $directory || ! $this->files->exists($parent) || ! $this->files->isDirectory($parent)) {
            return false;
        }

        return $this->files->isWritable($parent);
    }

    public function isValidUrlListFile(string $filePath): bool
    {
        if (! $this->files->exists($filePath) || ! $this->files->isReadable($filePath)) {
            return false;
        }

        $content = $this->files->get($filePath);
        $lines = explode("\n", $content);

        $validUrlCount = 0;
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (filter_var($line, FILTER_VALIDATE_URL) !== false) {
                $validUrlCount++;
            }
        }

        return $validUrlCount > 0;
    }
}
