<?php

namespace App\Services\Crawler\Storage;

use App\Models\ScrapedPage;
use App\Services\Crawler\Data\CrawlerContext;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Service for storing crawler results in database and file system.
 *
 * This service handles all persistence operations for crawler results,
 * including database storage, file organization, and metadata management.
 * It provides a clean interface for storing scraped data in multiple formats.
 */
class CrawlerStorageService
{
    public function __construct(
        private PageCategorizationService $categorizationService
    ) {}
    /**
     * Store crawler results in the database.
     *
     * Processes all crawled directories and stores their data in the
     * ScrapedPage model for easy querying and retrieval.
     *
     * @param CrawlerContext $context Crawler context with results
     * @return int Number of pages stored
     */
    public function storeResults(CrawlerContext $context): int
    {
        if (!$context->config) {
            return 0;
        }

        $outputDir = $context->config->outputDir;
        $label = $context->config->label;
        $crawlDir = "$outputDir/$label";

        if (!is_dir($crawlDir)) {
            return 0;
        }

        $stored = 0;
        $directories = $this->getDirectories($crawlDir);

        foreach ($directories as $dirNumber) {
            $dirPath = $crawlDir . '/' . Str::padLeft($dirNumber, 5, '0');
            $jsonFile = $dirPath . '/data_' . Str::padLeft($dirNumber, 5, '0') . '.json';

            if (!File::exists($jsonFile)) {
                continue;
            }

            try {
                $data = json_decode(File::get($jsonFile), true);
                if (!$data || !isset($data['page_url'])) {
                    continue;
                }

                // Get job ID from context if available
                $jobId = $context->request->getJobId();

                $this->storePageData($data, $dirPath, $label, $jobId);
                $stored++;
            } catch (\Throwable $e) {
                // Log error but continue processing
                \Log::warning("Failed to store page data from {$jsonFile}: {$e->getMessage()}");
            }
        }

        return $stored;
    }

    /**
     * Store a single page's data in the database.
     *
     * @param array $data Page data from JSON file
     * @param string $dirPath Directory path containing the data
     * @param string $label Crawler label
     * @param string|null $jobId Optional job ID
     * @return ScrapedPage|null
     */
    private function storePageData(array $data, string $dirPath, string $label, ?string $jobId = null): ?ScrapedPage
    {
        try {
            // Extract URL - it's stored as an array in the JSON
            $pageUrl = is_array($data['page_url']) ? $data['page_url'][0] : $data['page_url'];

            // Generate URL hash for lookups
            $urlHash = hash('sha256', $pageUrl);

            // Get categorization data
            $categorization = $this->categorizationService->categorize(
                $pageUrl,
                $data,
                $label,
                $jobId
            );

            // Check if page already exists (use hash for performance)
            $page = ScrapedPage::where('page_url_hash', $urlHash)->first();

            if (!$page) {
                $page = new ScrapedPage();
            }

            // Update basic page data
            $page->title = $data['title'] ?? null;
            $page->page_url = $pageUrl;
            $page->meta_img_url = $data['meta_img_url'] ?? null;
            $page->images = $data['images'] ?? null;
            $page->date = $data['date'] ?? null;
            $page->pdfs = $data['pdfs'] ?? null;
            $page->path = $dirPath;

            // Store raw JSON for reference
            $page->raw_json = $data;

            // Apply categorization data
            $page->site_category = $categorization['site_category'];
            $page->domain = $categorization['domain'];
            $page->subdomain = $categorization['subdomain'];
            $page->full_domain = $categorization['full_domain'];
            $page->access_level = $categorization['access_level'];
            $page->crawler_label = $categorization['crawler_label'];
            $page->crawler_job_id = $categorization['crawler_job_id'];
            $page->crawled_at = $categorization['crawled_at'];
            $page->image_count = $categorization['image_count'];
            $page->pdf_count = $categorization['pdf_count'];
            $page->content_length = $categorization['content_length'];
            $page->search_text = $categorization['search_text'];

            $page->save();

            return $page;
        } catch (\Throwable $e) {
            \Log::error("Failed to store page data: {$e->getMessage()}", [
                'data' => $data,
                'dirPath' => $dirPath,
            ]);
            return null;
        }
    }

    /**
     * Get all directory numbers from a crawl directory.
     *
     * @param string $crawlDir Crawl directory path
     * @return array Array of directory numbers
     */
    private function getDirectories(string $crawlDir): array
    {
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
     * Archive crawler results to a compressed file.
     *
     * Creates a ZIP archive of the crawler results for backup or distribution.
     *
     * @param CrawlerContext $context Crawler context
     * @param string|null $destination Destination path (auto-generated if null)
     * @return string|null Path to the archive, or null on failure
     */
    public function archiveResults(CrawlerContext $context, ?string $destination = null): ?string
    {
        if (!$context->config) {
            return null;
        }

        $outputDir = $context->config->outputDir;
        $label = $context->config->label;
        $crawlDir = "$outputDir/$label";

        if (!is_dir($crawlDir)) {
            return null;
        }

        if (!$destination) {
            $timestamp = now()->format('Y-m-d_H-i-s');
            $destination = storage_path("app/archives/crawler_{$label}_{$timestamp}.zip");
        }

        try {
            // Ensure destination directory exists
            File::ensureDirectoryExists(dirname($destination));

            // Create ZIP archive
            $zip = new \ZipArchive();
            if ($zip->open($destination, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                return null;
            }

            // Add files to archive
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($crawlDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($crawlDir) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }

            $zip->close();

            return $destination;
        } catch (\Throwable $e) {
            \Log::error("Failed to archive crawler results: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Export crawler results to JSON format.
     *
     * Creates a single JSON file with all crawled page data.
     *
     * @param CrawlerContext $context Crawler context
     * @param string|null $destination Destination path (auto-generated if null)
     * @return string|null Path to the exported file, or null on failure
     */
    public function exportToJson(CrawlerContext $context, ?string $destination = null): ?string
    {
        if (!$context->config) {
            return null;
        }

        $outputDir = $context->config->outputDir;
        $label = $context->config->label;
        $crawlDir = "$outputDir/$label";

        if (!is_dir($crawlDir)) {
            return null;
        }

        if (!$destination) {
            $timestamp = now()->format('Y-m-d_H-i-s');
            $destination = storage_path("app/exports/crawler_{$label}_{$timestamp}.json");
        }

        try {
            // Ensure destination directory exists
            File::ensureDirectoryExists(dirname($destination));

            $allData = [];
            $directories = $this->getDirectories($crawlDir);

            foreach ($directories as $dirNumber) {
                $dirPath = $crawlDir . '/' . Str::padLeft($dirNumber, 5, '0');
                $jsonFile = $dirPath . '/data_' . Str::padLeft($dirNumber, 5, '0') . '.json';

                if (File::exists($jsonFile)) {
                    $data = json_decode(File::get($jsonFile), true);
                    if ($data) {
                        $allData[] = $data;
                    }
                }
            }

            // Write to file
            File::put($destination, json_encode($allData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $destination;
        } catch (\Throwable $e) {
            \Log::error("Failed to export crawler results: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Clean up old crawler results.
     *
     * Removes crawler results older than the specified number of days.
     *
     * @param int $days Number of days to keep
     * @param string|null $label Specific label to clean (null for all)
     * @return int Number of directories cleaned
     */
    public function cleanupOldResults(int $days = 30, ?string $label = null): int
    {
        $outputDir = storage_path('app/private/crawled-data');
        if (!is_dir($outputDir)) {
            return 0;
        }

        $cutoffTime = now()->subDays($days)->timestamp;
        $cleaned = 0;

        $directories = $label
            ? [$outputDir . '/' . $label]
            : glob($outputDir . '/*', GLOB_ONLYDIR);

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $mtime = filemtime($dir);
            if ($mtime && $mtime < $cutoffTime) {
                File::deleteDirectory($dir);
                $cleaned++;
            }
        }

        return $cleaned;
    }
}
