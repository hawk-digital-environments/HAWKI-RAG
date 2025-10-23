<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CrawlAndConvert extends Command
{
        /**
         * Usage example:
         * php artisan crawl:and-convert "https://www.hawk.de/" \
         *   --max-pages=100000 \
         *   --output-dir=storage/app/private/crawled-data/hawk-full \
         *   --label="hawk-full" \
         *   --image-exceptions="data:image,.svg,icon,favicon,logo,sprite,placeholder" \
         *   --date="meta[property='og:updated_time']"
         */
    protected $signature = 'crawl:and-convert
        {url : The starting URL to crawl}
        {--max-pages=100 : Maximum number of pages to crawl}
        {--output-dir=storage/app/private/crawled-data : Directory to store crawled data}
        {--label= : Label for this crawl job}
        {--skip-images : Skip downloading images}
        {--image-exceptions= : Comma-separated substrings to ignore for images}
        {--date= : CSS selector for updated_time, e.g. meta[property=\'og:updated_time\']}';

    protected $description = 'Pipeline: first crawl a website, then convert all crawled PDFs to Markdown';

    public function handle(): int
    {
        $url             = $this->argument('url');
        $maxPages        = $this->option('max-pages');
        $outputDir       = $this->option('output-dir');
        $label           = $this->option('label');
        $skipImages      = $this->option('skip-images');
        $imageExceptions = $this->option('image-exceptions');
        $date            = $this->option('date');

        // === Step 1: Crawl ===
        $this->info('=== Step 1/2: Crawling ===');

        $crawlArgs = array_filter([
            'url'                 => $url,
            '--max-pages'         => $maxPages,
            '--output-dir'        => $outputDir,
            '--label'             => $label,
            '--image-exceptions'  => $imageExceptions,
            '--date'              => $date,
            ($skipImages ? '--skip-images' : null) => $skipImages ? true : null,
        ], fn($v) => $v !== null);

        $code1 = $this->call('crawlee:scrape', $crawlArgs);
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
}
