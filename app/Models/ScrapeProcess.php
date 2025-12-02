<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScrapeProcess extends Model
{
    protected $table = 'scrape_jobs';

    protected $fillable = [
        'url',
        'label',
        'job_id',
        'status',
        'config',
        'progress',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'config' => 'array',
    ];



    /* ----------------------------------
     | Relationships
     ---------------------------------- */
    public function metadata(): HasMany
    {
        return $this->hasMany(ScrapeMetadata::class, 'scrape_job_id');
    }

    public function elements(): HasMany
    {
        return $this->hasMany(ScrapedElement::class, 'scrape_job_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ScrapeMetadata::class, 'scrape_job_id');
    }

    /* ----------------------------------
     | Status Helpers
     ---------------------------------- */

    public function markRunning(): void
    {
        $this->update(['status' => 'running']);
    }

    public function markCompleted(bool $success = true): void
    {
        $this->update([
            'status' => $success ? 'completed' : 'failed'
        ]);
    }

    public function markFailed(string $reason = null): void
    {
        $payload = ['error' => $reason];

        // store final event
        $this->recordEvent('job_failed', $payload);

        $this->update(['status' => 'failed']);
    }


    /* ----------------------------------
     | Event Recording
     ---------------------------------- */

    public function recordEvent(string $eventName, array $data = []): ScrapeMetadata
    {
        return $this->events()->create([
            'event' => $eventName,
            'data'  => $data,
        ]);
    }


    /* ----------------------------------
     | Query Scopes
     ---------------------------------- */

    public function scopeRunning($query)
    {
        return $query->where('status', 'running');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }




}
