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

    public function process(): BelongsTo
    {
        return $this->belongsTo(ScrapeProcess::class, 'scrape_job_id');
    }
}
