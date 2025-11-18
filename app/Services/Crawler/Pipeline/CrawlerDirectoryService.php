<?php

namespace App\Services\Crawler\Pipeline;

use App\Services\Crawler\Data\DirectoryAnalysis;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class CrawlerDirectoryService
{
    public function __construct(
        private CrawlerUrlService $urlService
    ) {}

    /**
     * Get all existing numbered directories for a specific crawl label.
     *
     * Scans the output directory for subdirectories following the 5-digit naming
     * convention (00001, 00002, etc.) and returns them as sorted integers.
     * Used to determine which pages have been crawled and what index to start from
     * when continuing a crawl.
     *
     * @param string $outputDir Base output directory containing crawl data
     * @param string $label Label identifying the specific crawl session
     * @return array Sorted array of directory numbers (as integers)
     */
    public function getExistingDirectories(string $outputDir, string $label): array
    {
        $crawlDir = "$outputDir/$label";
        if (!is_dir($crawlDir)) {
            return [];
        }

        // Scan directory and filter for 5-digit numeric subdirectories
        return collect(scandir($crawlDir))
            ->filter(fn($item) => !in_array($item, ['.', '..']))
            ->filter(function ($item) use ($crawlDir) {
                $itemPath = "$crawlDir/$item";
                return is_dir($itemPath) && preg_match('/^\d{5}$/', $item);
            })
            ->map(fn($item) => (int)$item)  // Convert to integers
            ->sort()                         // Sort numerically
            ->values()
            ->toArray();
    }

    /**
     * Check if a crawl directory contains complete and valid data.
     *
     * Validates that a directory contains:
     * 1. Required files (site_XXXXX.txt and data_XXXXX.json)
     * 2. Files are not empty
     * 3. JSON contains valid structure with title and page_url fields
     *
     * Used to identify incomplete crawls that need to be resumed or re-crawled.
     *
     * @param string $dirPath Full path to the directory to check
     * @param int $dirNumber Directory number (used to format file names)
     * @return bool True if directory is complete, false otherwise
     */
    public function isDirectoryComplete(string $dirPath, int $dirNumber): bool
    {
        if (!is_dir($dirPath)) {
            return false;
        }

        $formattedId = Str::padLeft($dirNumber, 5, '0');

        // Check for required files
        $requiredFiles = [
            "site_{$formattedId}.txt",
            "data_{$formattedId}.json"
        ];

        foreach ($requiredFiles as $file) {
            $filePath = $dirPath . '/' . $file;

            // File must exist and not be empty
            if (!File::exists($filePath) || File::size($filePath) === 0) {
                return false;
            }
        }

        // Validate JSON file structure
        $jsonFile = $dirPath . "/data_{$formattedId}.json";
        if (File::exists($jsonFile)) {
            $decoded = rescue(
                fn() => json_decode(File::get($jsonFile), true),
                null,
                report: false
            );

            // JSON must be parseable and not empty
            if (blank($decoded)) {
                return false;
            }

            // Required fields must be present and filled
            if (!filled($decoded['title']) || !filled($decoded['page_url'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scan all crawl directories and analyze their completeness status.
     *
     * Performs a comprehensive analysis of all directories for a crawl session,
     * categorizing them into complete and incomplete. For incomplete directories,
     * attempts to extract the URL that was being processed. This analysis is
     * essential for determining how to resume an interrupted crawl.
     *
     * @param string $outputDir Base output directory containing crawl data
     * @param string $label Label identifying the crawl session
     * @return DirectoryAnalysis Analysis object containing categorized directories and metadata
     */
    public function scanDirectoriesForCompleteness(string $outputDir, string $label): DirectoryAnalysis
    {
        $directories = $this->getExistingDirectories($outputDir, $label);

        // Return empty analysis if no directories exist
        if (blank($directories)) {
            return new DirectoryAnalysis(
                complete: [],
                incomplete: [],
                lastComplete: 0,
                incompleteUrls: []
            );
        }

        // Partition directories into complete and incomplete
        [$completeDirectories, $incompleteDirectories] = collect($directories)
            ->partition(function ($dirNumber) use ($outputDir, $label) {
                $dirPath = "$outputDir/$label/" . Str::padLeft($dirNumber, 5, '0');
                return $this->isDirectoryComplete($dirPath, $dirNumber);
            });

        // Extract URLs from incomplete directories for resumption support
        $incompleteUrls = $incompleteDirectories
            ->mapWithKeys(function ($dirNumber) use ($outputDir, $label) {
                $dirPath = "$outputDir/$label/" . Str::padLeft($dirNumber, 5, '0');
                $extractedUrl = $this->urlService->extractUrlFromIncompleteDirectory($dirPath, $dirNumber);

                // Only include if URL was successfully extracted
                return filled($extractedUrl) ? [$dirNumber => $extractedUrl] : [];
            })
            ->toArray();

        // Find the highest complete directory number
        $lastComplete = blank($completeDirectories) ? 0 : $completeDirectories->max();

        return new DirectoryAnalysis(
            complete: $completeDirectories->values()->toArray(),
            incomplete: $incompleteDirectories->values()->toArray(),
            lastComplete: $lastComplete,
            incompleteUrls: $incompleteUrls
        );
    }

    /**
     * Setup and ensure the output directory exists for storing crawled data.
     *
     * Creates the crawled-data directory in private storage if it doesn't exist
     * and returns its absolute path. This directory serves as the root location
     * for all crawl session data.
     *
     * @return string|null Absolute path to the output directory, or null if creation failed
     */
    public function setupOutputDirectory(): ?string
    {
        $outputDir = realpath(storage_path('app/private/crawled-data'));

        // Create directory if it doesn't exist
        if (!$outputDir) {
            File::makeDirectory(storage_path('app/private/crawled-data'), 0755, true);
            $outputDir = realpath(storage_path('app/private/crawled-data'));
        }

        return $outputDir ?: null;
    }

    /**
     * Recursively empty a directory by removing all its contents.
     *
     * Deletes all files and subdirectories within the specified directory,
     * but keeps the directory itself. Uses a recursive iterator to traverse
     * the directory tree in child-first order, ensuring subdirectories are
     * emptied before being removed.
     *
     * @param string $dir Path to the directory to empty
     * @return void
     */
    public function emptyDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        // Create recursive iterator to traverse all files and subdirectories
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST  // Process children before parents
        );

        // Delete each file and directory
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
    }

    /**
     * Clear incomplete directories to prepare them for re-crawling.
     *
     * Empties the contents of specified incomplete directories so they can be
     * re-used when resuming a crawl. This ensures that incomplete data doesn't
     * interfere with the fresh crawl attempt.
     *
     * @param string $outputDir Base output directory
     * @param string $label Label identifying the crawl session
     * @param array $incompleteDirectoryNumbers Array of directory numbers to clear
     * @return int Count of directories successfully cleared
     */
    public function clearIncompleteDirectories(string $outputDir, string $label, array $incompleteDirectoryNumbers): int
    {
        $cleared = 0;

        foreach ($incompleteDirectoryNumbers as $incompleteDir) {
            // Format directory path with 5-digit padding
            $incompleteDirPath = "$outputDir/$label/" . Str::padLeft($incompleteDir, 5, '0');

            if (is_dir($incompleteDirPath)) {
                $this->emptyDirectory($incompleteDirPath);
                $cleared++;
            }
        }

        return $cleared;
    }
}
