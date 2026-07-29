<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngestionSource extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'source_id',
        'source_url',
        'task_id',
        'dataset_id',
        'last_scraped_at',
        'etag',
        'last_modified',
        'content_hash',
        'document_version',
        'temporal_workflow_id',
        'temporal_schedule_id',
        'index_status',
        'refresh_cadence',
        'raw_storage_path',
        'markdown_storage_path',
        'metadata',
        'ready_at',
    ];

    protected $casts = [
        'last_scraped_at' => 'datetime',
        'ready_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(PipelineTask::class, 'task_id', 'task_id');
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class, 'dataset_id', 'dataset_id');
    }
}
