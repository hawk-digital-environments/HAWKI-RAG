<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RagIngestionArtifact extends Model
{
    protected $fillable = [
        'pipeline_job_id',
        'pipeline_worker_event_id',
        'job_id',
        'task_id',
        'source_id',
        'dataset_id',
        'workflow_id',
        'run_id',
        'summary',
        'graph_preview',
        'occurred_at',
    ];

    protected $casts = [
        'summary' => 'array',
        'graph_preview' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function pipelineJob(): BelongsTo
    {
        return $this->belongsTo(PipelineJob::class);
    }

    public function workerEvent(): BelongsTo
    {
        return $this->belongsTo(PipelineWorkerEventRecord::class, 'pipeline_worker_event_id');
    }

    public function graphFailures(): HasMany
    {
        return $this->hasMany(RagGraphFailure::class);
    }
}
