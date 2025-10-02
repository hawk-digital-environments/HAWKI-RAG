<?php

namespace App\Services\Crawler;

use Illuminate\Support\Facades\File;

class CrawlerProgressService
{
    public function __construct(
        private CrawlerUrlService $urlService
    ) {}

    /**
     * Save crawling progress to a JSON file
     */
    public function saveProgress(string $label, int $directoryIndex, ?int $urlsProcessed = null): void
    {
        $progressFile = storage_path("app/private/crawler-progress-{$label}.json");
        $progressData = [
            'lastDirectoryIndex' => $directoryIndex,
            'timestamp' => now()->timestamp 
        ];
        
        if (filled($urlsProcessed)) {
            $progressData['urlsProcessed'] = $urlsProcessed;
        }
        
        File::put($progressFile, json_encode($progressData));
    }

    /**
     * Delete progress file for a specific label
     */
    public function deleteProgress(string $label): void
    {
        $progressFile = storage_path("app/private/crawler-progress-{$label}.json");
        
        if (File::exists($progressFile)) {
            File::delete($progressFile);
        }
    }

    /**
     * Calculate how many URLs to skip when continuing a sitemap crawl
     */
    public function calculateContinueOffset(string $outputDir, string $label, string $sourceType, ?array $allUrls = null, ?array $existingDirs = null): int
    {
        $progressFile = storage_path("app/private/crawler-progress-{$label}.json");
        
        if (File::exists($progressFile)) {
            $progressData = rescue(
                fn() => json_decode(File::get($progressFile), true),
                [],  // Default value if fails
                report: false
            );
            return $progressData['urlsProcessed'] ?? 0;
        }
        
        // Fallback: count existing directories or analyze URLs
        if ($sourceType === 'local' && filled($allUrls)) {
            return $this->urlService->countProcessedUrls($outputDir, $label, $allUrls);
        }
        
        return filled($existingDirs) ? count($existingDirs) : 0;
    }
} 