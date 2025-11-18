<?php

namespace App\Services\Crawler\Pipeline;

use Illuminate\Support\Facades\File;

class CrawlerProgressService
{
    public function __construct(
        private CrawlerUrlService $urlService
    ) {}

    /**
     * Save crawling progress to a JSON file for later resumption.
     *
     * Creates or updates a progress file in storage that tracks the current state
     * of a crawl session. This enables resuming from the last processed position
     * if the crawl is interrupted. The file is stored as crawler-progress-{label}.json
     * in the private storage directory.
     *
     * @param string $label Label identifying the crawl session
     * @param int $directoryIndex The last processed directory index (5-digit format)
     * @param int|null $urlsProcessed Optional count of URLs processed so far
     * @return void
     */
    public function saveProgress(string $label, int $directoryIndex, ?int $urlsProcessed = null): void
    {
        $progressFile = storage_path("app/private/crawler-progress-{$label}.json");

        // Build progress data with directory index and timestamp
        $progressData = [
            'lastDirectoryIndex' => $directoryIndex,
            'timestamp' => now()->timestamp
        ];

        // Include URL count if provided
        if (filled($urlsProcessed)) {
            $progressData['urlsProcessed'] = $urlsProcessed;
        }

        File::put($progressFile, json_encode($progressData));
    }

    /**
     * Delete the progress file for a specific crawl session.
     *
     * Removes the progress tracking file when a crawl is completed or when
     * starting a fresh crawl. This prevents old progress data from interfering
     * with new crawl sessions.
     *
     * @param string $label Label identifying the crawl session
     * @return void
     */
    public function deleteProgress(string $label): void
    {
        $progressFile = storage_path("app/private/crawler-progress-{$label}.json");

        // Delete the file if it exists
        if (File::exists($progressFile)) {
            File::delete($progressFile);
        }
    }

    /**
     * Calculate the offset (number of URLs to skip) when continuing a crawl.
     *
     * Determines how many URLs have already been processed to enable resuming from
     * the correct position. Uses multiple strategies in order of preference:
     * 1. Progress file data (most accurate)
     * 2. URL counting for local file sources (matches URLs against existing data)
     * 3. Directory count (fallback for remote sources)
     *
     * @param string $outputDir Base output directory containing crawled data
     * @param string $label Label identifying the crawl session
     * @param string $sourceType Type of source: 'local', 'sitemap', or 'direct'
     * @param array|null $allUrls Optional array of all URLs (required for local source URL counting)
     * @param array|null $existingDirs Optional array of existing directories (fallback method)
     * @return int Number of URLs to skip when continuing the crawl
     */
    public function calculateContinueOffset(string $outputDir, string $label, string $sourceType, ?array $allUrls = null, ?array $existingDirs = null): int
    {
        $progressFile = storage_path("app/private/crawler-progress-{$label}.json");

        // First priority: use saved progress data
        if (File::exists($progressFile)) {
            $progressData = rescue(
                fn() => json_decode(File::get($progressFile), true),
                [],  // Default value if fails
                report: false
            );
            return $progressData['urlsProcessed'] ?? 0;
        }

        // Second priority: for local sources, count processed URLs by matching against the list
        if ($sourceType === 'local' && filled($allUrls)) {
            return $this->urlService->countProcessedUrls($outputDir, $label, $allUrls);
        }

        // Fallback: count existing directories
        return filled($existingDirs) ? count($existingDirs) : 0;
    }
} 