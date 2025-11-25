<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeMetadata extends Model
{
    protected $table = 'scrape_events';

    public $timestamps = true;

    // Allow mass assignment for these fields
    protected $fillable = [
        'scrape_job_id',
        'event',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    /* ----------------------------------
     | Relationships
     ---------------------------------- */

    public function job(): BelongsTo
    {
        return $this->belongsTo(ScrapeProcess::class, 'scrape_job_id');
    }

    /* ----------------------------------
     | Query Scopes
     ---------------------------------- */

    public function scopeForJob($query, int $jobId)
    {
        return $query->where('scrape_job_id', $jobId);
    }

    public function scopeEventType($query, string $event)
    {
        return $query->where('event', $event);
    }

    /* ----------------------------------
     | Convenience Helpers
     ---------------------------------- */

    public function isError(): bool
    {
        return isset($this->data['error']) && $this->data['error'] !== null;
    }

    public function isUrlScraped(): bool
    {
        return $this->event === 'url_scraped';
    }
}
