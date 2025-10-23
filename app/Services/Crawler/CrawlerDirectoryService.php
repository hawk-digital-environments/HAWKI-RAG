<?php

namespace App\Services\Crawler;

use App\Services\Crawler\Data\DirectoryAnalysis;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CrawlerDirectoryService
{
    public function __construct(
        private CrawlerUrlService $urlService
    ) {}

    /**
     * Get existing directories for a label
     */
    public function getExistingDirectories(string $outputDir, string $label): array
    {
        $crawlDir = "$outputDir/$label";
        if (!is_dir($crawlDir)) {
            return [];
        }

        return collect(scandir($crawlDir))
            ->filter(fn($item) => !in_array($item, ['.', '..']))
            ->filter(function ($item) use ($crawlDir) {
                $itemPath = "$crawlDir/$item";
                return is_dir($itemPath) && preg_match('/^\d{5}$/', $item);
            })
            ->map(fn($item) => (int)$item)
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Check if a directory is complete
     */
    public function isDirectoryComplete(string $dirPath, int $dirNumber): bool
    {
        if (!is_dir($dirPath)) {
            return false;
        }

        $formattedId = Str::padLeft($dirNumber, 5, '0');

        $requiredFiles = [
            "site_{$formattedId}.txt",
            "data_{$formattedId}.json"
        ];

        foreach ($requiredFiles as $file) {
            $filePath = $dirPath . '/' . $file;

            if (!File::exists($filePath) || File::size($filePath) === 0) {
                return false;
            }
        }

        // Validate JSON file
        $jsonFile = $dirPath . "/data_{$formattedId}.json";
        if (File::exists($jsonFile)) {
            $decoded = rescue(
                fn() => json_decode(File::get($jsonFile), true),
                null,
                report: false
            );

            if (blank($decoded)) {
                return false;
            }

            if (!filled($decoded['title']) || !filled($decoded['page_url'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scan all directories for completeness analysis
     */
    public function scanDirectoriesForCompleteness(string $outputDir, string $label): DirectoryAnalysis
    {
        $directories = $this->getExistingDirectories($outputDir, $label);

        if (blank($directories)) {
            return new DirectoryAnalysis(
                complete: [],
                incomplete: [],
                lastComplete: 0,
                incompleteUrls: []
            );
        }

        [$completeDirectories, $incompleteDirectories] = collect($directories)
            ->partition(function ($dirNumber) use ($outputDir, $label) {
                $dirPath = "$outputDir/$label/" . Str::padLeft($dirNumber, 5, '0');
                return $this->isDirectoryComplete($dirPath, $dirNumber);
            });

        // Process incomplete directories for URL extraction
        $incompleteUrls = $incompleteDirectories
            ->mapWithKeys(function ($dirNumber) use ($outputDir, $label) {
                $dirPath = "$outputDir/$label/" . Str::padLeft($dirNumber, 5, '0');
                $extractedUrl = $this->urlService->extractUrlFromIncompleteDirectory($dirPath, $dirNumber);

                return filled($extractedUrl) ? [$dirNumber => $extractedUrl] : [];
            })
            ->toArray();

        $lastComplete = blank($completeDirectories) ? 0 : $completeDirectories->max();

        return new DirectoryAnalysis(
            complete: $completeDirectories->values()->toArray(),
            incomplete: $incompleteDirectories->values()->toArray(),
            lastComplete: $lastComplete,
            incompleteUrls: $incompleteUrls
        );
    }

    /**
     * Setup and validate output directory
     */
    public function setupOutputDirectory(): ?string
    {
        $outputDir = realpath(storage_path('app/private/crawled-data'));
        if (!$outputDir) {
            File::makeDirectory(storage_path('app/private/crawled-data'), 0755, true);
            $outputDir = realpath(storage_path('app/private/crawled-data'));
        }

        return $outputDir ?: null;
    }

    /**
     * Empty a directory recursively
     */
    public function emptyDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
    }

    /**
     * Clear incomplete directories for re-scraping
     */
    public function clearIncompleteDirectories(string $outputDir, string $label, array $incompleteDirectoryNumbers): int
    {
        $cleared = 0;

        foreach ($incompleteDirectoryNumbers as $incompleteDir) {
            $incompleteDirPath = "$outputDir/$label/" . Str::padLeft($incompleteDir, 5, '0');

            if (is_dir($incompleteDirPath)) {
                $this->emptyDirectory($incompleteDirPath);
                $cleared++;
            }
        }

        return $cleared;
    }
}
