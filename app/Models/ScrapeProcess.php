<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ScrapeProcess extends Model
{
    protected $table = 'scrape_jobs';

    protected $fillable = [
        'url',
        'label',
        'job_id',
        'stage',
        'request',
    ];

    protected $casts = [
        'request' => 'array',
    ];



    /* ----------------------------------
     | Relationships
     ---------------------------------- */
    public function elements(): HasMany
    {
        return $this->hasMany(ScrapedElement::class, 'job_id', 'job_id');
    }

    public function stats(): HasOne
    {
        return $this->hasOne(ScrapeStatistics::class, 'job_id', 'job_id');
    }

    /* ----------------------------------
     | Status Helpers
     ---------------------------------- */

    public function markRunning(): void
    {
        $this->update(['stage' => 'running']);
    }

    public function markCompleted(bool $success = true): void
    {
        $this->update([
            'stage' => $success ? 'completed' : 'failed'
        ]);
    }

    /* ----------------------------------
     | Query Scopes
     ---------------------------------- */

    public function scopeRunning($query)
    {
        return $query->where('stage', 'running');
    }

    public function scopeCompleted($query)
    {
        return $query->where('stage', 'completed');
    }

    public function scopeFailed($query)
    {
        return $query->where('stage', 'failed');
    }




}
