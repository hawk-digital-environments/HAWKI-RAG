<?php

namespace App\Services\Crawler\Pipeline;

use App\Services\Crawler\Storage\CrawlerStorageManager;

/**
 * Service for managing crawler progress tracking.
 *
 * Uses CrawlerStorageManager for filesystem operations.
 */
class CrawlerProgressService
{
    public function __construct(
        private CrawlerStorageManager $storage,
        private CrawlerUrlService $urlService
    ) {}

    /**
     * Save crawling progress to a JSON file for later resumption.
     */
    public function saveProgress(string $label, int $directoryIndex, ?int $urlsProcessed = null): void
    {
        $progressFile = $this->storage->progressFilePath($label);

        $progressData = [
            'lastDirectoryIndex' => $directoryIndex,
            'timestamp' => now()->timestamp
        ];

        if (filled($urlsProcessed)) {
            $progressData['urlsProcessed'] = $urlsProcessed;
        }

        $this->storage->put($progressFile, json_encode($progressData));
    }

    /**
     * Delete the progress file for a specific crawl session.
     */
    public function deleteProgress(string $label): void
    {
        $progressFile = $this->storage->progressFilePath($label);

        if ($this->storage->exists($progressFile)) {
            $this->storage->delete($progressFile);
        }
    }

    /**
     * Calculate the offset (number of URLs to skip) when continuing a crawl.
     */
    public function calculateContinueOffset(
        string $outputDir,
        string $label,
        string $sourceType,
        ?array $allUrls = null,
        ?array $existingDirs = null
    ): int {
        $progressFile = $this->storage->progressFilePath($label);

        // First priority: use saved progress data
        if ($this->storage->exists($progressFile)) {
            $progressData = rescue(
                fn() => json_decode($this->storage->get($progressFile), true),
                [],
                report: false
            );
            return $progressData['urlsProcessed'] ?? 0;
        }

        // Second priority: for local sources, count processed URLs
        if ($sourceType === 'local' && filled($allUrls)) {
            return $this->urlService->countProcessedUrls($outputDir, $label, $allUrls);
        }

        // Fallback: count existing directories
        return filled($existingDirs) ? count($existingDirs) : 0;
    }
}
