<?php

declare(strict_types=1);

namespace App\Services\Scrape\Validation;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;

#[Singleton]
readonly class ScrapePathValidator
{
    public function __construct(
        private Filesystem $files,
        private ConfigRepository $config,
    ) {
    }

    public function isValidUrlOrFile(string $urlOrPath): bool
    {
        if ($this->hasUrlScheme($urlOrPath)) {
            return $this->isAllowedUrl($urlOrPath);
        }

        if ($this->files->exists($urlOrPath) && $this->files->isReadable($urlOrPath) && $this->isAllowedLocalPath($urlOrPath)) {
            return true;
        }

        return false;
    }

    public function isValidDirectory(string $directory): bool
    {
        if (! $this->isAllowedLocalPath($directory, mustExist: false)) {
            return false;
        }

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
        if (! $this->files->exists($filePath) || ! $this->files->isReadable($filePath) || ! $this->isAllowedLocalPath($filePath)) {
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

            if ($this->isAllowedUrl($line)) {
                $validUrlCount++;
            }
        }

        return $validUrlCount > 0;
    }

    private function isAllowedUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function hasUrlScheme(string $value): bool
    {
        return preg_match('/\A[A-Za-z][A-Za-z0-9+.-]*:\/\//', $value) === 1;
    }

    private function isAllowedLocalPath(string $path, bool $mustExist = true): bool
    {
        $candidate = $mustExist ? realpath($path) : $this->canonicalizePendingPath($path);
        if ($candidate === false || $candidate === '') {
            return false;
        }

        foreach ($this->allowedRoots() as $root) {
            if ($candidate === $root || str_starts_with($candidate, $root.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    private function canonicalizePendingPath(string $path): string|false
    {
        $parent = dirname($path);
        $resolvedParent = realpath($parent);
        if ($resolvedParent === false) {
            return false;
        }

        return $resolvedParent.DIRECTORY_SEPARATOR.basename($path);
    }

    /**
     * @return list<string>
     */
    private function allowedRoots(): array
    {
        $roots = (array) $this->config->get('scraper.allowed_local_roots', []);

        return array_values(array_filter(array_map(
            static fn (mixed $path): string|false => is_string($path) && trim($path) !== ''
                ? realpath($path)
                : false,
            $roots
        )));
    }
}
