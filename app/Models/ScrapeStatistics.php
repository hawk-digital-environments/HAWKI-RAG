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
        'sessions' => 'integer',
        'requests' => 'integer',
        'total_urls' => 'integer',
        'target_urls' => 'integer',
        'completed_urls' => 'integer',
        'failed_urls' => 'integer',
        'pdfs_downloaded' => 'integer',
        'images_downloaded' => 'integer',
        'duration_seconds' => 'integer',
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
