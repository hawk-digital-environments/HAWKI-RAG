<?php

namespace App\Console\Commands\Crawler; 

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use App\Services\Crawler\CrawlerProgressService;
use App\Services\Crawler\CrawlerUrlService;
use App\Services\Concerns\Crawler\ManagesDirectories;
use App\Services\Concerns\Crawler\DirectoryCompleteness;
use App\Services\Concerns\Crawler\HandlesFileSystem;
use App\Services\Concerns\Crawler\ManagesExistingData;
use App\Services\Concerns\Crawler\BuildsConfiguration;
use App\Services\Concerns\Crawler\ExecutesCrawler;

class CrawleeScraper extends Command
{
    use ManagesDirectories, DirectoryCompleteness, HandlesFileSystem, 
        ManagesExistingData, BuildsConfiguration, ExecutesCrawler;

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
                            {--date= : CSS selector for date elements (e.g., ".date", "#publication-date", "time", "meta[property=\"og:updated_time\"]")}
                            {--max-concurrency=4 : Maximum number of parallel requests running at a time}
                            {--max-rpm=60 : Maximum requests per minute to throttle overall rate}
                            {--request-delay= : Delay between requests in milliseconds (overrides RPM throttle when set)}';

    protected $description = 'Scrape websites using Crawlee';

    public function __construct(
        private CrawlerProgressService $progressService,
        private CrawlerUrlService $urlService
    ) {
        parent::__construct();
    }

    public function handle()
    {
        // Process and validate input URL
        [$url, $sitemapUrls, $isLocalFile, $baseUrl, $sourceType] = $this->processInputUrl();
        if (!$url) return 1;
        
        // Setup output directory
        $outputDir = $this->setupOutputDirectory();
        if (!$outputDir) return 1;
        
        $label = $this->option('label') ?: 'default';
        
        // Analyze existing data and get user choice
        [$shouldContinue, $startFromIndex] = $this->handleExistingData($outputDir, $label);
        if ($shouldContinue === null) return 0; // User cancelled
        
        // Build crawler configuration
        $config = $this->buildCrawlerConfig($url, $isLocalFile, $baseUrl, $outputDir, $label, $startFromIndex, $shouldContinue, $sourceType);
        
        // Handle URL continuation logic
        $config = $this->handleUrlContinuation($config, $shouldContinue, $startFromIndex, $sourceType, $sitemapUrls, $outputDir, $label);
        
        // Execute the crawler
        $success = $this->executeNodeCrawler($config, $isLocalFile, $shouldContinue, $startFromIndex, $outputDir, $label);
        
        if ($success) {
            // Handle successful completion
            $this->handleSuccessfulCompletion($sourceType, $config, $startFromIndex, $shouldContinue, $outputDir, $label, $sitemapUrls);
            return 0;
        }
        
        return 1;
    }

    /**
     * Process and validate the input URL
     */
    private function processInputUrl(): array
    {
        $url = $this->argument('url');
        
        if (blank($url)) {
            $url = $this->ask('Enter the website URL to crawl (e.g., https://www.hawk.de/en)');
        }
        
        $isLocalFile = File::exists($url) && File::isReadable($url);
        
        if (!$isLocalFile && !filter_var($url, FILTER_VALIDATE_URL)) {
            $this->error('Invalid URL provided or file not found/readable.');
            return [null, [], false, null, null];
        }
        if (!$isLocalFile) {
            $host = Str::lower((string) parse_url($url, PHP_URL_HOST));
            if ($host && in_array($host, self::SKIP_HOSTS, true)) {
                $this->warn("Skipping crawl: {$url} is under Forbidden Hosts.");
                return [null, [], false, null, null];
            }
        }
        
        $sitemapUrls = [];
        $baseUrl = null;
        
        if ($isLocalFile) {
            $this->info("Using local sitemap file: $url");
            
            $sitemapUrls = collect(explode("\n", File::get($url)))
                ->map(fn($line) => trim($line))
                ->filter(fn($line) => filled($line))
                ->filter(fn($line) => filter_var($line, FILTER_VALIDATE_URL) !== false)
                ->reject(function ($line) {
                    $host = Str::lower((string) parse_url($line, PHP_URL_HOST));
                    return $host && in_array($host, self::SKIP_HOSTS, true);
                })
                ->values()
                ->toArray();
            
            if (blank($sitemapUrls)) {
                $this->error('The sitemap file does not contain any valid URLs.');
                return [null, [], false, null, null];
            }
            
            $this->info("Found " . count($sitemapUrls) . " valid URLs in the sitemap file.");
            
            $parsedUrl = parse_url($sitemapUrls[0]);
            $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
        } 
        
        // Determine source type
        $sourceType = 'direct';
        if ($isLocalFile) {
            $sourceType = 'local';
        } else {
            $lowerUrl = Str::lower($url);
            if (Str::contains($lowerUrl, ['sitemap', '.xml'])) {
                $sourceType = 'sitemap';
            }
        }
        
        return [$url, $sitemapUrls, $isLocalFile, $baseUrl, $sourceType];
    }
} 
