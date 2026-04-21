<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CrawlAndConvert extends Command
{
        /**
         * Usage example:
         * php artisan crawl:and-convert "https://www.hawk.de/" \
         *   --max-pages=100000 \
         *   --output-dir=/app/shared/crawled-data/hawk-full \
         *   --label="hawk-full" \
         *   --image-exceptions="data:image,.svg,icon,favicon,logo,sprite,placeholder" \
         *   --date="meta[property='og:updated_time']"
         */
    protected $signature = 'crawl:and-convert
        {url : The starting URL to crawl}
        {--max-pages=100 : Maximum number of pages to crawl}
        {--output-dir= : Crawl output directory (absolute path or path relative to the canonical crawled-data root)}
        {--label= : Label for this crawl job}
        {--skip-images : Skip downloading images}
        {--image-exceptions= : Comma-separated substrings to exclude from image scraping}
        {--date= : CSS selector for updated_time, e.g. meta[property=\'og:updated_time\']}
        {--max-concurrency=4 : Maximum number of parallel requests running at a time}
        {--max-rpm=60 : Maximum requests per minute to throttle overall rate}
        {--request-delay= : Delay between requests in milliseconds (overrides RPM throttle when set)}';

    protected $description = 'Pipeline: first crawl a website, then convert all crawled PDFs to Markdown';

    public function handle(): int
    {
        $url             = $this->argument('url');
        $maxPages        = $this->option('max-pages');
        $label           = $this->option('label');
        $skipImages      = $this->option('skip-images');
        $imageExceptions = $this->option('image-exceptions');
        $date            = $this->option('date');
        $maxConcurrency  = $this->option('max-concurrency');
        $maxRpm          = $this->option('max-rpm');
        $requestDelay    = $this->option('request-delay');
        $outputDir       = $this->resolveOutputDir($this->option('output-dir'), $label);

        // === Step 1: Crawl ===
        $this->info('=== Step 1/2: Crawling ===');
        $this->line("Using output directory: {$outputDir}");

        $crawlArgs = array_filter([
            'url'                 => $url,
            '--max-pages'         => $maxPages,
            '--output-dir'        => $outputDir,
            '--label'             => $label,
            '--image-exceptions'  => $imageExceptions,
            '--date'              => $date,
            '--max-concurrency'   => $maxConcurrency,
            '--max-rpm'           => $maxRpm,
            '--request-delay'     => $requestDelay,
            ($skipImages ? '--skip-images' : null) => $skipImages ? true : null,
        ], fn($v) => $v !== null);

        $code1 = $this->call('scraper:scrape', $crawlArgs);
        if ($code1 !== 0) {
            $this->error("Crawler exited with code {$code1}.");
            return $code1;
        }

        // === Step 2: Convert PDFs ===
        $this->info('=== Step 2/2: Converting PDFs ===');

        $code2 = $this->call('convert:crawled-pdfs', [
            'outputDir' => $outputDir,
        ]);
        if ($code2 !== 0) {
            $this->error("Converter exited with code {$code2}.");
            return $code2;
        }

        $this->info('Pipeline completed successfully ✅');
        return 0;
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
