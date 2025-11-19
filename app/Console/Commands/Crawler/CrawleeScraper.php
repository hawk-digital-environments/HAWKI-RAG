<?php

namespace App\Console\Commands\Crawler;

use App\Services\Crawler\CrawlerPipelineService;
use App\Services\Crawler\Data\CrawlerJobRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CrawleeScraper extends Command
{
    protected $signature = 'crawlee:scrape
                            {url? : The starting URL to crawl}
                            {--max-pages=100 : Maximum number of pages to crawl}
                            {--output-dir= : Directory to store crawled data}
                            {--label= : Label for this crawl job (auto-generated if not provided)}
                            {--skip-images : Skip downloading images to save time and bandwidth}
                            {--image-exceptions= : Comma-separated list of CSS selectors for elements to exclude from image scraping}
                            {--date= : CSS selector for date elements (e.g., ".date", "#publication-date", "time", "meta[property=\"og:updated_time\"]")}
                            {--max-concurrency=4 : Maximum number of parallel requests running at a time}
                            {--max-rpm=60 : Maximum requests per minute to throttle overall rate}
                            {--request-delay= : Delay between requests in milliseconds (overrides RPM throttle when set)}';

    protected $description = 'Scrape websites using Crawlee';

    public function __construct(
        private CrawlerPipelineService $pipeline
    ) {
        parent::__construct();
    }

    /**
     * Execute the crawlee:scrape command.
     *
     * This command is a thin I/O wrapper that:
     * 1. Gathers input from the user
     * 2. Creates a CrawlerJobRequest
     * 3. Sets up event listeners for console output
     * 4. Executes the pipeline
     * 5. Displays the result
     *
     * @return int Command exit code (self::SUCCESS or self::FAILURE)
     */
    public function handle(): int
    {
        try {
            // Get URL from argument or prompt
            $url = $this->argument('url');
            if (blank($url)) {
                $url = $this->ask('Enter the website URL to crawl (e.g., https://www.hawk.de/en)');
            }

            if (blank($url)) {
                $this->error('URL is required');
                return self::FAILURE;
            }

            // Get label with auto-generated fallback
            $label = $this->option('label') ?: $this->generateLabel($url);

            // Display the label being used
            $this->info("Using crawl label: {$label}");

            // Parse image exceptions
            $imageExceptions = $this->parseImageExceptions();

            // Parse date selector
            $dateSelector = $this->option('date');
            if ($dateSelector) {
                $this->info("Using date selector: {$dateSelector}");
            }

            // Create job request
            $request = new CrawlerJobRequest(
                url: $url,
                label: $label,
                maxPages: (int) $this->option('max-pages'),
                outputDir: $this->option('output-dir') ?: '',
                skipImages: (bool) $this->option('skip-images'),
                imageExceptions: $imageExceptions,
                dateSelector: $dateSelector,
                maxConcurrency: (int) $this->option('max-concurrency'),
                maxRpm: (int) $this->option('max-rpm'),
                requestDelay: $this->option('request-delay') ? (int) $this->option('request-delay') : null,
            );

            // Setup event listeners for console output
            $this->setupEventListeners();

            // Determine strategy for existing data (ask user interactively)
            $strategy = CrawlerPipelineService::STRATEGY_CONTINUE;

            // Execute pipeline with output streaming
            // Note: Database storage is now mandatory for all scrape operations
            $result = $this->pipeline->execute(
                request: $request,
                existingDataStrategy: $strategy,
                storeInDatabase: true,
                outputCallback: function (string $type, string $buffer) {
                    // Stream crawler output to console
                    if ($type === 'out') {
                        fwrite(STDOUT, $buffer);
                        fflush(STDOUT);
                    } else {
                        fwrite(STDERR, $buffer);
                        fflush(STDERR);
                    }
                }
            );

            // Display result
            if ($result->isSuccessful()) {
                $this->newLine();
                $this->info($result->getSummary());

                // Display statistics
                if (!empty($result->statistics)) {
                    $this->displayStatistics($result->statistics);
                }

                // Display warnings if any
                if (!empty($result->warnings)) {
                    foreach ($result->warnings as $warning) {
                        $this->warn($warning['message']);
                    }
                }

                return self::SUCCESS;
            }

            // Display errors
            $this->newLine();
            $this->error($result->getSummary());
            foreach ($result->errors as $error) {
                $this->error($error['message']);
            }

            return self::FAILURE;

        } catch (\Throwable $e) {
            $this->error('An error occurred: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Generate a unique label for a crawl session.
     *
     * Creates a label based on the domain name and current timestamp.
     *
     * @param string $url Starting URL
     * @return string Generated label
     */
    private function generateLabel(string $url): string
    {
        // Extract domain from URL
        $parsed = parse_url($url);
        $host = $parsed['host'] ?? 'unknown';

        // Clean the domain for use in label (remove www, replace dots with underscores)
        $cleanHost = str_replace(['www.', '.'], ['', '_'], $host);

        // Add timestamp to make it unique
        $timestamp = now()->format('Ymd_His');

        return "{$cleanHost}_{$timestamp}";
    }

    /**
     * Parse image exceptions from command option.
     *
     * @return array|null
     */
    private function parseImageExceptions(): ?array
    {
        if (!$this->option('image-exceptions')) {
            return null;
        }

        $imageExceptions = collect(explode(',', $this->option('image-exceptions')))
            ->map(fn($item) => trim($item))
            ->filter(fn($item) => filled($item))
            ->values()
            ->toArray();

        if (!empty($imageExceptions)) {
            $this->info("Using image exceptions: " . implode(', ', $imageExceptions));
        }

        return $imageExceptions ?: null;
    }

    /**
     * Setup event listeners for console output.
     *
     * @return void
     */
    private function setupEventListeners(): void
    {
        $events = $this->pipeline->getEventService();

        // Listen for validation events
        $events->on('validation.started', function ($context) {
            $this->info('Validating input...');
        });

        $events->on('validation.completed', function ($context, $success) {
            if ($success) {
                $this->info('Validation passed.');
            }
        });

        // Listen for configuration events
        $events->on('configuration.started', function ($context) {
            $this->info('Processing configuration...');
        });

        $events->on('existing_data.found', function ($context) {
            if ($context->analysis) {
                $this->displayExistingDataStats($context->analysis);
            }
        });

        // Listen for execution events
        $events->on('execution.started', function ($context) {
            $this->info('Starting crawler...');
        });

        // Listen for storage events
        $events->on('storage.started', function ($context) {
            $this->info('Storing results in database...');
        });

        $events->on('storage.completed', function ($context) {
            $stored = $context->getMetadata('storedPages', 0);
            $this->info("Stored {$stored} pages in database.");
        });

        // Listen for errors and warnings
        $events->on('error', function ($context, $message) {
            $this->error($message);
        });

        $events->on('warning', function ($context, $message) {
            $this->warn($message);
        });
    }

    /**
     * Display statistics about existing scraped data.
     *
     * @param \App\Services\Crawler\Data\DirectoryAnalysis $analysis
     * @return void
     */
    private function displayExistingDataStats($analysis): void
    {
        $totalExisting = $analysis->getTotalExisting();
        $totalComplete = $analysis->getTotalComplete();
        $totalIncomplete = $analysis->getTotalIncomplete();

        $this->newLine();
        $this->info("Found existing scraped data:");
        $this->info("  Total directories: {$totalExisting}");
        $this->info("  Complete directories: {$totalComplete}");
        $this->info("  Incomplete directories: {$totalIncomplete}");

        if ($totalComplete > 0) {
            $this->info("  Last complete directory: " . Str::padLeft($analysis->lastComplete, 5, '0'));
        }

        if ($totalIncomplete > 0) {
            $incompleteList = collect($analysis->incomplete)
                ->map(fn($num) => Str::padLeft($num, 5, '0'))
                ->implode(', ');
            $this->warn("  Incomplete directories: {$incompleteList}");
        }
        $this->newLine();
    }

    /**
     * Display job statistics.
     *
     * @param array $statistics
     * @return void
     */
    private function displayStatistics(array $statistics): void
    {
        $this->newLine();
        $this->info('Statistics:');
        foreach ($statistics as $key => $value) {
            $label = Str::headline($key);
            $this->info("  {$label}: {$value}");
        }
    }
}
