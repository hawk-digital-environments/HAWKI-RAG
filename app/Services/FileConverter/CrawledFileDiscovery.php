<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use App\Services\Pipeline\Console\ConsoleWorkflowIO;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

readonly class CrawledFileDiscovery
{
    public function __construct(
        private ConfigRepository $config,
        private Filesystem $files,
    ) {
    }

    public function pickOutputDir(ConsoleWorkflowIO $io): ?string
    {
        $roots = array_values(array_filter([
            $this->crawledDataRoot(),
        ], fn (string $path): bool => $this->files->isDirectory($path)));

        if ($roots === []) {
            $io->error('No shared crawl directories found. Provide outputDir explicitly.');

            return null;
        }

        $root = count($roots) === 1
            ? $roots[0]
            : $io->choice('Select the crawl root to inspect', $roots, 0);

        $dirs = $this->files->directories($root);
        if ($dirs === []) {
            $io->error("No crawl folders found under: {$root}");

            return null;
        }

        $selected = $io->choice('Select a crawl folder', $dirs, 0);
        $io->info("Selected: {$selected}");

        return $selected;
    }

    public function resolveOutputDir(string $outputDir): string
    {
        if ($this->isAbsolutePath($outputDir)) {
            return $outputDir;
        }

        return $this->crawledDataRoot() . DIRECTORY_SEPARATOR . ltrim($outputDir, DIRECTORY_SEPARATOR);
    }

    public function makePathRelative(string $path, string $baseDir): string
    {
        $path = str_replace('\\', '/', realpath($path) ?: $path);
        $baseDir = str_replace('\\', '/', realpath($baseDir) ?: $baseDir);
        if (str_starts_with($path, $baseDir)) {
            return ltrim(substr($path, strlen($baseDir)), '/');
        }

        return $path;
    }

    /**
     * @param array<int, string> $extensions
     * @return array<int, string>
     */
    public function collectDocumentPaths(string $outputDir, array $extensions, bool $scanAll): array
    {
        $paths = [];
        $root = rtrim($outputDir, DIRECTORY_SEPARATOR);
        $finder = Finder::create()
            ->files()
            ->ignoreUnreadableDirs()
            ->in($root);

        foreach ($finder as $file) {
            if (! in_array(strtolower($file->getExtension()), $extensions, true)) {
                continue;
            }

            $path = $file->getPathname();
            if (! $scanAll && ! str_contains(str_replace('\\', '/', $path), '/files/')) {
                continue;
            }

            $paths[] = $path;
        }

        sort($paths);

        return $paths;
    }

    private function crawledDataRoot(): string
    {
        return rtrim((string) $this->config->get('config.crawled_data_root', '/app/shared'), DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
