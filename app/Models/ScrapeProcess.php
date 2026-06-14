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
}
