<?php

namespace App\Services\Crawler\Pipeline;

use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

class CrawlerUrlService
{
    /**
     * Count how many URLs from the provided list have been successfully processed.
     *
     * Scans the output directory for crawled data and matches processed URLs against
     * the provided URL list. The count stops at the first unprocessed URL, ensuring
     * sequential continuation support. Each directory (named with 5-digit format like
     * 00001, 00002) contains a data_XXXXX.json file with the crawled URL.
     *
     * @param string $outputDir Base output directory containing crawled data
     * @param string $label Label/prefix identifying the specific crawl session
     * @param array $allUrls Array of URLs to check against processed data
     * @return int Number of URLs from the list that have been processed sequentially
     */
    public function countProcessedUrls(string $outputDir, string $label, array $allUrls): int
    {
        $dataDir = "$outputDir/$label";

        // Return 0 if the data directory doesn't exist yet
        if (!is_dir($dataDir)) {
            return 0;
        }

        // Build a map of all processed URLs from the numbered directories
        $processedUrls = collect(scandir($dataDir))
            ->filter(fn($dir) => preg_match('/^\d{5}$/', $dir)) // Match 5-digit directory names
            ->mapWithKeys(function ($dir) use ($dataDir) {
                $jsonFile = "$dataDir/$dir/data_$dir.json";

                // Safely read and decode the JSON file
                $data = rescue(
                    fn() => json_decode(File::get($jsonFile), true),
                    [],
                    report: false
                );

                // Extract page_url from the data and add to map
                return filled($data['page_url'][0] ?? null)
                    ? [$data['page_url'][0] => true]
                    : [];
            });

        // Count consecutive processed URLs from the start of the list
        $processedCount = 0;
        foreach ($allUrls as $url) {
            if ($processedUrls->has($url)) {
                $processedCount++;
            } else {
                // Stop counting at the first unprocessed URL for sequential continuation
                break;
            }
        }

        return $processedCount;
    }

    /**
     * Extract the URL from an incomplete directory's JSON data file.
     *
     * Attempts to read the data_XXXXX.json file in an incomplete crawl directory
     * and extract the page URL. Used for resuming incomplete crawls by identifying
     * which URL was being processed. The function safely handles missing or malformed
     * JSON files.
     *
     * @param string $dirPath Full path to the directory containing the data file
     * @param int $dirNumber Directory number (will be formatted to 5 digits)
     * @return string|null The extracted URL, or null if the file doesn't exist or is invalid
     */
    public function extractUrlFromIncompleteDirectory(string $dirPath, int $dirNumber): ?string
    {
        return rescue(function () use ($dirPath, $dirNumber) {
            // Format directory number to 5-digit string (e.g., 1 -> 00001)
            $formattedId = Str::padLeft($dirNumber, 5, '0');
            $jsonFile = $dirPath . "/data_{$formattedId}.json";

            // Check if JSON file exists and has content
            if (File::exists($jsonFile) && File::size($jsonFile) > 0) {
                $decoded = json_decode(File::get($jsonFile), true);

                // Extract the first URL from page_url array
                if (filled($decoded['page_url']) && is_array($decoded['page_url'])) {
                    return Arr::first($decoded['page_url']);
                }
            }

            return null;
        }, null, report: false);
    }
} 