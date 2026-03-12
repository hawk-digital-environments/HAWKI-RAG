<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeStatistics extends Model
{
    protected $table = 'scrape_statistics';

    protected $fillable = [
        'job_id',
        'sessions',
        'requests',
        'total_urls',
        'target_urls',
        'completed_urls',
        'failed_urls',
        'current_url',
        'errors',
        'warnings',
        'pdfs_downloaded',
        'images_downloaded',
        'started_at',
        'completed_at',
        'duration_seconds',
    ];

    protected $casts = [
        'total_urls' => 'integer',
        'completed_urls' => 'integer',
        'failed_urls' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'current_url' => 'string',
        'errors' => 'array',
        'warnings' => 'array',
        'stats' => 'array'
    ];


    public function process(): BelongsTo
    {
        return $this->belongsTo(ScrapeProcess::class, 'job_id', 'job_id');
    }

    public function progressPercentage(): int
    {
        return ( $this->completed_urls * 100 ) / $this->total_urls;
    }




    public function addError(array $error): void
    {
        $errors = $this->errors ?? [];
        $errors[] = $error;
        $this->errors = $errors;
    }

    public function getErrors(): array
    {
        return $this->errors ?? [];
    }

    public function addWarning(array $warning): void
    {
        $warnings = $this->warnings ?? [];
        $warnings[] = $warning;
        $this->warnings = $warnings;
    }

    public function getWarnings(): array
    {
        return $this->warnings ?? [];
    }


}
