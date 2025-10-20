<?php

namespace App\Console\Commands\Crawler;

use App\Services\Crawler\CrawlerOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CrawleeScraper extends Command
{
    private const SKIP_HOSTS = [
        'publikationsserver.hawk.de',
    ];

    protected $signature = 'crawlee:scrape
                            {url? : The starting URL to crawl}
                            {--max-pages=100 : Maximum number of pages to crawl}
                            {--output-dir=storage/app/private/crawled-data : Directory to store crawled data}
                            {--label= : Label for this crawl job}
                            {--skip-images : Skip downloading images to save time and bandwidth}
                            {--image-exceptions= : Comma-separated list of CSS selectors for elements to exclude from image scraping}
                            {--date= : CSS selector for date elements (e.g., ".date", "#publication-date", "time", "meta[property=\"og:updated_time\"]")}';

    protected $description = 'Scrape websites using Crawlee';

    public function __construct(
        private CrawlerOrchestrator $orchestrator
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            // Get URL
            $url = $this->argument('url');
            if (blank($url)) {
                $url = $this->ask('Enter the website URL to crawl (e.g., https://www.hawk.de/en)');
            }

            if (blank($url)) {
                $this->error('URL is required');
                return self::FAILURE;
            }

            // Check if URL is in skip list (for direct URLs)
            if (!$this->isLocalFile($url) && $this->isHostForbidden($url)) {
                $this->warn("Skipping crawl: {$url} is under Forbidden Hosts.");
                return self::SUCCESS;
            }

            // Process URL first to validate and get info
            try {
                $urlOptions = $this->orchestrator->processUrl($url);

                if ($urlOptions->isLocal()) {
                    $this->info("Using local sitemap file: {$url}");

                    // Filter out forbidden hosts from sitemap URLs
                    $filteredUrls = $this->filterForbiddenHosts($urlOptions->sitemapUrls);
                    $filteredCount = count($urlOptions->sitemapUrls) - count($filteredUrls);

                    if ($filteredCount > 0) {
                        $this->warn("Filtered out {$filteredCount} URLs from forbidden hosts.");
                    }

                    if (empty($filteredUrls)) {
                        $this->error('No valid URLs remaining after filtering forbidden hosts.');
                        return self::FAILURE;
                    }

                    $this->info("Found " . count($filteredUrls) . " valid URLs in the sitemap file.");

                    // TODO: Update orchestrator to accept pre-filtered URLs
                    // For now, the orchestrator will process all URLs from the sitemap
                }
            } catch (\InvalidArgumentException $e) {
                $this->error($e->getMessage());
                return self::FAILURE;
            }

            // Get options
            $label = $this->option('label') ?: 'default';
            $maxPages = (int) $this->option('max-pages');
            $skipImages = (bool) $this->option('skip-images');

            // Parse image exceptions
            $imageExceptions = null;
            if ($this->option('image-exceptions')) {
                $imageExceptions = collect(explode(',', $this->option('image-exceptions')))
                    ->map(fn($item) => trim($item))
                    ->filter(fn($item) => filled($item))
                    ->values()
                    ->toArray();

                if (!empty($imageExceptions)) {
                    $this->info("Using image exceptions: " . implode(', ', $imageExceptions));
                }
            }

            // Date selector
            $dateSelector = filled($this->option('date')) ? $this->option('date') : null;
            if ($dateSelector) {
                $this->info("Using date selector: {$dateSelector}");
            }

            // Run crawler with callbacks for user interaction
            $result = $this->orchestrator->crawl(
                url: $url,
                label: $label,
                maxPages: $maxPages,
                skipImages: $skipImages,
                imageExceptions: $imageExceptions,
                dateSelector: $dateSelector,
                shouldContinueCallback: function ($existingDirs, $directoryAnalysis) {
                    // Display statistics
                    $this->displayExistingDataStats($existingDirs, $directoryAnalysis);

                    // Ask user what to do
                    return $this->choice(
                        'Existing scraped data found. What would you like to do?',
                        [
                            'continue' => 'Continue and re-scrape incomplete directories',
                            'restart' => 'Start a new scrape from the beginning',
                            'cancel' => 'Cancel the scrape operation'
                        ],
                        'continue'
                    );
                },
                shouldRestartCallback: function () {
                    return $this->confirm(
                        'This will DELETE all existing scraped data in the output directory. Are you sure you want to continue?',
                        false
                    );
                }
            );

            // Display result
            if ($result->isSuccessful()) {
                $this->info('Crawling completed successfully.');
                if ($result->output) {
                    $this->info($result->output);
                }
                if (!$skipImages) {
                    $this->info('Images have been downloaded to the crawl directory.');
                }
                return self::SUCCESS;
            }

            $this->error('Crawling failed:');
            if ($result->error) {
                $this->error($result->error);
            }
            return self::FAILURE;

        } catch (\Throwable $e) {
            $this->error('An error occurred: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Check if URL host is in forbidden list
     */
    private function isHostForbidden(string $url): bool
    {
        $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
        return $host && in_array($host, self::SKIP_HOSTS, true);
    }

    /**
     * Check if URL is a local file
     */
    private function isLocalFile(string $url): bool
    {
        return file_exists($url) && is_readable($url);
    }

    /**
     * Filter out URLs with forbidden hosts
     */
    private function filterForbiddenHosts(array $urls): array
    {
        return collect($urls)
            ->reject(fn($url) => $this->isHostForbidden($url))
            ->values()
            ->toArray();
    }

    /**
     * Display statistics about existing scraped data
     */
    private function displayExistingDataStats(array $existingDirs, $directoryAnalysis): void
    {
        $totalExisting = count($existingDirs);
        $totalComplete = $directoryAnalysis->getTotalComplete();
        $totalIncomplete = $directoryAnalysis->getTotalIncomplete();
        $lastCompleteDir = $directoryAnalysis->lastComplete;

        $this->info("Found existing scraped data:");
        $this->info("  Total directories: {$totalExisting}");
        $this->info("  Complete directories: {$totalComplete}");
        $this->info("  Incomplete directories: {$totalIncomplete}");

        if ($totalComplete > 0) {
            $this->info("  Last complete directory: " . Str::padLeft($lastCompleteDir, 5, '0'));
        }

        if ($totalIncomplete > 0) {
            $incompleteList = collect($directoryAnalysis->incomplete)
                ->map(fn($num) => Str::padLeft($num, 5, '0'))
                ->implode(', ');

            $this->warn("  Incomplete directories: {$incompleteList}");
        }
    }
}
