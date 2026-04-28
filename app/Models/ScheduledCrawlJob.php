<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class ScheduledCrawlJob extends Model
{
    public const PERIOD_DAY = 'per-day';
    public const PERIOD_WEEK = 'per-week';
    public const PERIOD_MONTH = 'per-month';

    protected $fillable = [
        'url',
        'period',
        'job_id',
        'collection',
        'graph_enabled',
        'crawled_root',
        'sitemap_pages',
        'max_pages',
        'rescrape_failed',
        'skip_images',
        'metadata_json',
        'active',
        'last_run_at',
        'next_run_at',
    ];

    protected $casts = [
        'graph_enabled' => 'boolean',
        'sitemap_pages' => 'integer',
        'rescrape_failed' => 'boolean',
        'skip_images' => 'boolean',
        'metadata_json' => 'array',
        'active' => 'boolean',
        'last_run_at' => 'datetime',
        'next_run_at' => 'datetime',
    ];

    public function runs(): HasMany
    {
        return $this->hasMany(ScheduledCrawlJobRun::class, 'scheduled_crawl_job_id');
    }

    public function computeNextRunAt(?Carbon $from = null): Carbon
    {
        $from ??= now();

        return match ($this->period) {
            self::PERIOD_WEEK => $from->copy()->addWeek(),
            self::PERIOD_MONTH => $from->copy()->addMonthNoOverflow(),
            default => $from->copy()->addDay(),
        };
    }
}
