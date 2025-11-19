<?php

namespace App\Services\Crawler\Storage;

use App\Models\ScrapedPage;
use App\Services\Crawler\Data\CrawlerContext;

/**
 * Service for storing crawler results in database and filesystem.
 *
 * Handles persistence operations using the CrawlerStorageManager for
 * filesystem abstraction, enabling support for multiple storage backends.
 */
class CrawlerStorageService
{
    public function __construct(
        private CrawlerStorageManager $storage,
        private PageCategorizationService $categorizationService
    ) {}

    /**
     * Store crawler results in the database.
     */
    public function storeResults(CrawlerContext $context): int
    {
        if (!$context->config) {
            return 0;
        }

        $label = $context->config->label;

        if (!$this->storage->isDirectory($label)) {
            return 0;
        }

        $stored = 0;
        $directories = $this->storage->getNumberedDirectories($label);

        foreach ($directories as $dirNumber) {
            $dataFile = $this->storage->dataFilePath($label, $dirNumber);

            if (!$this->storage->exists($dataFile)) {
                continue;
            }

            try {
                $data = json_decode($this->storage->get($dataFile), true);

                if (!$data || !isset($data['page_url'])) {
                    continue;
                }

                $jobId = $context->request->getJobId();
                $dirPath = $this->storage->directoryPath($label, $dirNumber);

                $this->storePageData($data, $dirPath, $label, $jobId);
                $stored++;
            } catch (\Throwable $e) {
                \Log::warning("Failed to store page data from {$dataFile}: {$e->getMessage()}");
            }
        }

        return $stored;
    }

    /**
     * Store a single page's data in the database.
     */
    private function storePageData(
        array $data,
        string $dirPath,
        string $label,
        ?string $jobId = null
    ): ?ScrapedPage {
        try {
            // Extract URL from array format
            $pageUrl = $this->extractValue($data['page_url']);
            $urlHash = hash('sha256', $pageUrl);

            // Get categorization data
            $categorization = $this->categorizationService->categorize(
                $pageUrl,
                $data,
                $label,
                $jobId
            );

            // Find or create page
            $page = ScrapedPage::where('page_url_hash', $urlHash)->first();

            if (!$page) {
                $page = new ScrapedPage();
            }

            // Update page data
            $page->fill([
                'title' => $this->extractValue($data['title'] ?? null),
                'page_url' => $pageUrl,
                'meta_img_url' => $this->extractValue($data['meta_img_url'] ?? null),
                'images' => $data['images'] ?? null,
                'date' => $this->extractValue($data['date'] ?? null),
                'pdfs' => $data['pdfs'] ?? null,
                'path' => $dirPath,
                'raw_json' => $data,
            ]);
            https://projekte.g.hawk.de/medien/5df0c5b6e3a69/gallery/5e24b28de3e1b.jpg
            // Apply categorization
            $page->fill($categorization);

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
     * Extract scalar value from array or return as-is.
     */
    private function extractValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return !empty($value) ? $value[0] : null;
        }

        return $value;
    }

    /**
     * Export crawler results to JSON format.
     */
    public function exportToJson(CrawlerContext $context, ?string $destination = null): ?string
    {
        if (!$context->config) {
            return null;
        }

        $label = $context->config->label;

        if (!$this->storage->isDirectory($label)) {
            return null;
        }

        try {
            $allData = [];
            $directories = $this->storage->getNumberedDirectories($label);

            foreach ($directories as $dirNumber) {
                $dataFile = $this->storage->dataFilePath($label, $dirNumber);

                if ($this->storage->exists($dataFile)) {
                    $data = json_decode($this->storage->get($dataFile), true);
                    if ($data) {
                        $allData[] = $data;
                    }
                }
            }

            $json = json_encode($allData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            if ($destination) {
                file_put_contents($destination, $json);
                return $destination;
            }

            return $json;
        } catch (\Throwable $e) {
            \Log::error("Failed to export crawler results: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Clean up old crawler results.
     *
     * Removes crawler results older than the specified number of days.
     */
    public function cleanupOldResults(int $days = 30, ?string $label = null): int
    {
        $cutoffTime = now()->subDays($days)->timestamp;
        $cleaned = 0;

        $labels = $label ? [$label] : collect($this->storage->directories())
            ->map(fn($path) => basename($path))
            ->filter(fn($name) => !preg_match('/^temp-/', $name))
            ->all();

        foreach ($labels as $labelToCheck) {
            if (!$this->storage->isDirectory($labelToCheck)) {
                continue;
            }

            try {
                $lastModified = $this->storage->lastModified($labelToCheck);

                if ($lastModified < $cutoffTime) {
                    $this->storage->deleteDirectory($labelToCheck);
                    $cleaned++;
                }
            } catch (\Throwable $e) {
                \Log::warning("Failed to clean up label {$labelToCheck}: {$e->getMessage()}");
            }
        }

        return $cleaned;
    }

    /**
     * Sync crawled data from local filesystem to configured storage disk.
     *
     * The Node.js crawler always writes to local filesystem. This method
     * syncs that data to the configured storage disk (SFTP, S3, etc).
     */
    public function syncToConfiguredDisk(string $label, string $localPath): bool
    {
        // If using local disk, no sync needed
        if ($this->storage->diskName() === 'local') {
            return true;
        }

        try {
            \Log::info("Syncing crawled data from local to {$this->storage->diskName()} for label: {$label}");

            if (!is_dir($localPath)) {
                \Log::warning("Local path does not exist: {$localPath}");
                return false;
            }

            // Get all files recursively from local directory
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($localPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            $synced = 0;
            foreach ($files as $file) {
                if ($file->isFile()) {
                    // Get relative path from the label directory
                    $relativePath = str_replace($localPath . '/', '', $file->getPathname());
                    $storagePath = $label . '/' . $relativePath;

                    // Read local file and write to storage disk
                    $contents = file_get_contents($file->getPathname());
                    $this->storage->put($storagePath, $contents);
                    $synced++;
                }
            }

            \Log::info("Synced {$synced} files to {$this->storage->diskName()} for label: {$label}");
            return true;

        } catch (\Throwable $e) {
            \Log::error("Failed to sync data to configured disk: {$e->getMessage()}");
            return false;
        }
    }
}
