<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PipelineWorkerEventRecord extends Model
{
    protected $table = 'pipeline_worker_events';

    protected $fillable = [
        'pipeline_job_id',
        'event_id',
        'job_id',
        'task_id',
        'source_id',
        'workflow_id',
        'run_id',
        'activity_id',
        'attempt',
        'event_type',
        'producer',
        'stage',
        'phase',
        'status',
        'payload_hash',
        'payload',
        'occurred_at',
        'processed_at',
    ];

    protected $casts = [
        'attempt' => 'integer',
        'payload' => 'array',
        'occurred_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function pipelineJob(): BelongsTo
    {
        return $this->belongsTo(PipelineJob::class);
    }
}
