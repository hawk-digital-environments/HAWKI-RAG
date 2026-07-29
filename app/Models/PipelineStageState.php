<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineStageState extends Model
{
    protected $fillable = [
        'pipeline_job_id',
        'job_id',
        'stage',
        'status',
        'counts',
        'metadata',
        'errors',
        'warnings',
        'retry_count',
        'max_retries',
        'started_at',
        'completed_at',
        'failed_at',
        'last_transition_at',
    ];

    protected $casts = [
        'counts' => 'array',
        'metadata' => 'array',
        'errors' => 'array',
        'warnings' => 'array',
        'retry_count' => 'integer',
        'max_retries' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'last_transition_at' => 'datetime',
    ];

    public function pipelineJob(): BelongsTo
    {
        return $this->belongsTo(PipelineJob::class);
    }
}
