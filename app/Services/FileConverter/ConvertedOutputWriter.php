<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use App\Services\FileConverter\Exceptions\ConversionOutputException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

readonly class ConvertedOutputWriter
{
    public function __construct(
        private Filesystem $files,
        private CrawledFileDiscovery $discovery,
    ) {
    }

    public function makeStagingDir(string $destDir): string
    {
        return dirname($destDir)
            . DIRECTORY_SEPARATOR
            . '.'
            . basename($destDir)
            . '.tmp-'
            . (string) Str::uuid();
    }

    /**
     * @param array<string, string> $files
     * @return array<int, string>
     */
    public function writeConvertedFiles(string $stagingDir, array $files): array
    {
        $this->files->makeDirectory($stagingDir, 0755, true, true);
        $written = [];

        foreach ($files as $relative => $content) {
            $outPath = $stagingDir . '/' . ltrim($relative, '/');
            $this->files->ensureDirectoryExists(dirname($outPath));
            $this->files->put($outPath, $content);
            $written[] = $this->discovery->makePathRelative($outPath, $stagingDir);
        }

        return $written;
    }

    public function replaceDirectory(string $sourceDir, string $destDir): void
    {
        if (! $this->files->isDirectory($sourceDir)) {
            throw ConversionOutputException::missingStagingDirectory($sourceDir);
        }

        if ($this->files->isDirectory($destDir) && ! $this->files->deleteDirectory($destDir)) {
            throw ConversionOutputException::removeExistingOutputFailed($destDir);
        }

        if ($this->files->isFile($destDir) && ! $this->files->delete($destDir)) {
            throw ConversionOutputException::removeBlockingFileFailed($destDir);
        }

        if (! @rename($sourceDir, $destDir)) {
            throw ConversionOutputException::publishFailed($sourceDir, $destDir);
        }
    }

    public function writeFileAtomically(string $path, string $content): void
    {
        $this->files->ensureDirectoryExists(dirname($path));
        $tmp = $path . '.tmp-' . (string) Str::uuid();
        $this->files->put($tmp, $content);

        if (! @rename($tmp, $path)) {
            $this->files->delete($tmp);
            throw ConversionOutputException::atomicWriteFailed($path);
        }
    }

    public function readFile(string $path): string
    {
        return (string) $this->files->get($path);
    }

    public function deleteDirectoryIfExists(?string $path): void
    {
        if (is_string($path) && $this->files->isDirectory($path)) {
            $this->files->deleteDirectory($path);
        }
    }

    /**
     * @return array<int, string>
     */
    public function collectCachedOutputFiles(string $destDir): array
    {
        if (! is_dir($destDir)) {
            return [];
        }

        $files = [];
        $finder = Finder::create()
            ->files()
            ->ignoreUnreadableDirs()
            ->in($destDir);

        foreach ($finder as $file) {
            if ($file->getFilename() === 'conversion_meta.json') {
                continue;
            }
            $files[] = $this->discovery->makePathRelative($file->getPathname(), $destDir);
        }
        sort($files);

        return $files;
    }
}
