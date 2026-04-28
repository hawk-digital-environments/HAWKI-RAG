<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledCrawlJobRun extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PRECHECKING = 'prechecking';
    public const STATUS_FAILED_PRECHECK = 'failed_precheck';
    public const STATUS_RUNNING_SCRAPER = 'running_scraper';
    public const STATUS_SCRAPER_FAILED = 'scraper_failed';
    public const STATUS_RUNNING_INGEST = 'running_ingest';
    public const STATUS_INGEST_FAILED = 'ingest_failed';
    public const STATUS_DISPATCHED = 'dispatched';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'scheduled_crawl_job_id',
        'job_id',
        'url',
        'period',
        'crawled_root',
        'collection',
        'graph_enabled',
        'pipeline_mode',
        'scraper_command',
        'ingest_command',
        'status',
        'exit_code',
        'stdout',
        'stderr',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'graph_enabled' => 'boolean',
        'exit_code' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function scheduledJob(): BelongsTo
    {
        return $this->belongsTo(ScheduledCrawlJob::class, 'scheduled_crawl_job_id');
    }
}
