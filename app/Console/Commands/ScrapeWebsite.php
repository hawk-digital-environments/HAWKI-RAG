<?php

namespace App\Console\Commands;

use App\Services\ScrapeService\ScraperPipelineService;
use App\Services\ScrapeService\Data\ScrapeJobRequest;
use App\Services\ScrapeService\ScrapeService;
use Illuminate\Console\Command;

class ScrapeWebsite extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'scraper:scrape
                            {url? : The starting URL to crawl}
                            {--max-pages=100 : Maximum number of pages to crawl}
                            {--output-dir= : Directory to store crawled data}
                            {--label= : Label for this crawl job (auto-generated if not provided)}
                            {--skip-images : Skip downloading images to save time and bandwidth}
                            {--image-exceptions= : Comma-separated list of CSS selectors for elements to exclude from image scraping}
                            {--date= : CSS selector for date elements (e.g., ".date", "#publication-date", "time", "meta[property=\"og:updated_time\"]")}
                            {--max-concurrency=4 : Maximum number of parallel requests running at a time}
                            {--max-rpm=60 : Maximum requests per minute to throttle overall rate}
                            {--request-delay= : Delay between requests in milliseconds (overrides RPM throttle when set)}
                            {--discovery-mode : Bool to discover urls inside the given page';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape websites';

        public function __construct(
        private readonly ScraperPipelineService $pipeline
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        try {
            // Get URL from argument or prompt
            $url = $this->argument('url');
            if (blank($url)) {
                $url = $this->ask('Enter the website URL to crawl (e.g., https://www.hawk.de/en)');
            }

            if (blank($url)) {
                $this->error('URL is required');
            }

            // Get label with auto-generated fallback
            $label = $this->option('label');

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
            $request = [
                'url' => $url,
                'label' => $label,
                'maxPages' => (int)$this->option('max-pages'),
                'outputDir' => $this->option('output-dir') ?: '',
                'skipImages' => (bool)$this->option('skip-images'),
                'imageExceptions' => $imageExceptions,
                'dateSelector' => $dateSelector,
                'maxConcurrency' => (int)$this->option('max-concurrency'),
                'maxRpm' => (int)$this->option('max-rpm'),
                'requestDelay' => $this->option('request-delay') ? (int)$this->option('request-delay') : null,
            ];

            $service = new ScrapeService();
            $service->startPipeline($request,
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

        } catch (\Throwable $e) {
            $this->error('An error occurred: ' . $e->getMessage());
        }
    }

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
}
