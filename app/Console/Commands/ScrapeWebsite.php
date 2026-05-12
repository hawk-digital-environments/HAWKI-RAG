<?php

namespace App\Console\Commands;

use App\Services\ScrapeService\ScrapeService;
use App\Support\PipelineExitCode;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

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
                            {--output-dir= : Crawl output directory (absolute path or path relative to the canonical crawled-data root)}
                            {--label= : Label for this crawl job (auto-generated if not provided)}
                            {--skip-images : Skip downloading images to save time and bandwidth}
                            {--image-exceptions= : Comma-separated substrings to exclude from image scraping}
                            {--date= : CSS selector for date elements (e.g., ".date", "#publication-date", "time", "meta[property=\"og:updated_time\"]")}
                            {--max-concurrency=4 : Maximum number of parallel requests running at a time}
                            {--max-rpm=60 : Maximum requests per minute to throttle overall rate}
                            {--request-delay= : Delay between requests in milliseconds (overrides RPM throttle when set)}
                            {--discovery-mode : Bool to discover urls inside the given page}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scrape websites';

    public function __construct(
        private ScrapeService $scrapeService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            // Get URL from argument or prompt
            $url = $this->argument('url');
            if (blank($url) && $this->input->isInteractive()) {
                $url = $this->ask('Enter the website URL to crawl (e.g., https://www.hawk.de/en)');
            }

            if (blank($url)) {
                $this->error('URL is required');
                return PipelineExitCode::VALIDATION_FAILURE;
            }

            // Get label with auto-generated fallback
            $label = $this->resolveLabel($this->option('label'), (string) $url);
            $outputDir = $this->resolveOutputDir($this->option('output-dir'), $label);

            // Display the label being used
            $this->info("Using crawl label: {$label}");
            $this->info("Using output directory: {$outputDir}");

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
                'outputDir' => $outputDir,
                'skipImages' => (bool)$this->option('skip-images'),
                'imageExceptions' => $imageExceptions,
                'dateSelector' => $dateSelector,
                'maxConcurrency' => (int)$this->option('max-concurrency'),
                'maxRpm' => (int)$this->option('max-rpm'),
                'requestDelay' => $this->option('request-delay') ? (int)$this->option('request-delay') : null,
                'discoveryMode' => (bool)$this->option('discovery-mode'),
            ];

            $result = $this->scrapeService->startPipeline($request,
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

            if (!$result->success) {
                foreach ($result->errors as $error) {
                    $this->error(is_array($error) ? json_encode($error) : (string) $error);
                }

                return PipelineExitCode::RUNTIME_FAILURE;
            }

            return PipelineExitCode::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('An error occurred: ' . $e->getMessage());
            return PipelineExitCode::RUNTIME_FAILURE;
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

    private function resolveLabel(?string $label, string $url): string
    {
        if (filled($label)) {
            return (string) $label;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $base = is_string($host) && $host !== '' ? $host : 'crawl';

        return Str::slug($base . '-' . now()->format('Ymd-His'), '-');
    }

    private function resolveOutputDir(?string $outputDir, ?string $label): string
    {
        if (filled($outputDir)) {
            return $this->resolvePath((string) $outputDir);
        }

        $root = $this->getCrawledDataRoot();
        if (blank($label)) {
            return $root;
        }

        return $root . DIRECTORY_SEPARATOR . Str::slug((string) $label, '-');
    }

    private function resolvePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return $this->getCrawledDataRoot() . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    private function getCrawledDataRoot(): string
    {
        return rtrim((string) config('config.crawled_data_root', '/app/shared/crawled-data'), DIRECTORY_SEPARATOR);
    }

    private function isAbsolutePath(string $path): bool
    {
        return Str::startsWith($path, ['/','\\']) || preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }
}
