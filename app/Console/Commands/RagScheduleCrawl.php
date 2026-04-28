<?php

namespace App\Console\Commands;

use App\Models\ScheduledCrawlJob;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RagScheduleCrawl extends Command
{
    protected $signature = 'rag:schedule-crawl
        {--url= : Target website URL}
        {--period=per-day : per-day|per-week|per-month}
        {--job-id= : Optional fixed job ID}
        {--collection= : Optional collection label}
        {--graph=true : true|false}
        {--crawled-root= : Crawled root path}
        {--sitemap-pages= : Sitemap pages limit}
        {--max-pages= : Max pages for full crawl}
        {--rescrape-failed= : true|false}
        {--skip-images= : true|false}
        {--metadata= : JSON metadata payload}';

    protected $description = 'Create a scheduled crawl job that runs through Make-based pipeline commands';

    public function handle(): int
    {
        $url = trim((string) $this->option('url'));
        if ($url === '') {
            $this->error('--url is required.');
            return self::FAILURE;
        }

        $period = trim((string) $this->option('period'));
        if (!in_array($period, [ScheduledCrawlJob::PERIOD_DAY, ScheduledCrawlJob::PERIOD_WEEK, ScheduledCrawlJob::PERIOD_MONTH], true)) {
            $this->error('--period must be one of: per-day, per-week, per-month.');
            return self::FAILURE;
        }

        $metadata = null;
        $metadataRaw = trim((string) $this->option('metadata'));
        if ($metadataRaw !== '') {
            $metadata = json_decode($metadataRaw, true);
            if (!is_array($metadata)) {
                $this->error('--metadata must be valid JSON object/array.');
                return self::FAILURE;
            }
        }

        $jobId = trim((string) $this->option('job-id'));
        if ($jobId === '') {
            $jobId = 'job_date_' . now()->format('Y_m_d');
        }

        $crawledRoot = trim((string) $this->option('crawled-root'));
        if ($crawledRoot === '') {
            $crawledRoot = (string) config('scheduler_pipeline.default_crawled_root', '/app/shared/crawled-data');
        }

        $sitemapPagesOpt = trim((string) $this->option('sitemap-pages'));
        $sitemapPages = $sitemapPagesOpt !== ''
            ? max(1, (int) $sitemapPagesOpt)
            : (int) config('scheduler_pipeline.default_sitemap_pages', 100);

        $maxPages = trim((string) $this->option('max-pages'));
        if ($maxPages === '') {
            $maxPages = (string) config('scheduler_pipeline.default_max_pages_full', '');
        }

        $graphEnabled = $this->toBoolOption((string) $this->option('graph'), true);
        $rescrapeFailed = $this->toBoolOption((string) $this->option('rescrape-failed'), (bool) config('scheduler_pipeline.default_rescrape_failed', false));
        $skipImages = $this->toBoolOption((string) $this->option('skip-images'), (bool) config('scheduler_pipeline.default_skip_images', true));

        $job = ScheduledCrawlJob::create([
            'url' => $url,
            'period' => $period,
            'job_id' => $jobId,
            'collection' => $this->option('collection') ? trim((string) $this->option('collection')) : null,
            'graph_enabled' => $graphEnabled,
            'crawled_root' => $crawledRoot,
            'sitemap_pages' => $sitemapPages,
            'max_pages' => $maxPages !== '' ? $maxPages : null,
            'rescrape_failed' => $rescrapeFailed,
            'skip_images' => $skipImages,
            'metadata_json' => $metadata,
            'active' => true,
            'next_run_at' => now(),
        ]);

        $this->info('Scheduled crawl job created.');
        $this->line('id: ' . $job->id);
        $this->line('url: ' . $job->url);
        $this->line('period: ' . $job->period);
        $this->line('job_id: ' . $job->job_id);
        $this->line('next_run_at: ' . optional($job->next_run_at)->toDateTimeString());

        return self::SUCCESS;
    }

    private function toBoolOption(string $value, bool $default): bool
    {
        $normalized = Str::lower(trim($value));
        if ($normalized === '') {
            return $default;
        }

        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => $default,
        };
    }
}
