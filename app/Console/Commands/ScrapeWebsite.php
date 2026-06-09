<?php

namespace App\Console\Commands;

use App\Services\Scrape\ScrapeCommandInputBuilder;
use App\Services\Scrape\ScrapeService;
use App\Support\PipelineExitCode;
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
        private ScrapeService $scrapeService,
        private ScrapeCommandInputBuilder $inputBuilder,
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
            if (blank($url) && $this->input->isInteractive() && ! $this->inputBuilder->automationEnabled()) {
                $url = $this->ask('Enter the website URL to crawl (e.g., https://www.hawk.de/en)');
            }

            if (blank($url)) {
                $this->error('URL is required');
                return PipelineExitCode::VALIDATION_FAILURE;
            }

            $request = $this->inputBuilder->request((string) $url, $this->options());

            // Display the label being used
            $this->info("Using crawl label: {$request['label']}");
            $this->info("Using output directory: {$request['outputDir']}");

            if ($request['imageExceptions']) {
                $this->info('Using image exceptions: '.$request['imageExceptions']);
            }

            if ($request['dateSelector']) {
                $this->info("Using date selector: {$request['dateSelector']}");
            }

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
}
