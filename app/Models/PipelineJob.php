<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineJob extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PENDING = self::STATUS_QUEUED;
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_PARTIAL = self::STATUS_FAILED;
    public const STATUS_CANCEL_REQUESTED = self::STATUS_FAILED;
    public const STATUS_CANCELLED = self::STATUS_FAILED;

    public const ACTIVE_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_RUNNING,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_SKIPPED,
        self::STATUS_FAILED,
    ];

    public const TYPE_SCRAPE = 'scrape';
    public const TYPE_CONVERT = 'convert';
    public const TYPE_INGEST = 'ingest';
    public const TYPE_GRAPH = 'graph';

    protected $fillable = [
        'job_id',
        'task_id',
        'source_id',
        'parent_job_id',
        'job_type',
        'status',
        'current_stage',
        'dataset_path',
        'source_url',
        'local_path',
        'content_hash',
        'temporal_workflow_id',
        'temporal_run_id',
        'temporal_schedule_id',
        'index_status',
        'error_message',
        'label',
        'total_documents',
        'processed_documents',
        'failed_documents',
        'skipped_documents',
        'metadata',
        'started_at',
        'completed_at',
        'finished_at',
    ];

    protected $casts = [
        'total_documents' => 'integer',
        'processed_documents' => 'integer',
        'failed_documents' => 'integer',
        'skipped_documents' => 'integer',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(PipelineTask::class, 'task_id', 'task_id');
    }

    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStageState::class);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_SKIPPED,
        ], true);
    }
}
