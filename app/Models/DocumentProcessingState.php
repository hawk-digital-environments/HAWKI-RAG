<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentProcessingState extends Model
{
    protected $table = 'document_processing_state';

    public const STAGE_SCRAPE = 'scrape';
    public const STAGE_CONVERT = 'convert';
    public const STAGE_CHUNK = 'chunk';
    public const STAGE_EMBED = 'embed';
    public const STAGE_GRAPH_EXTRACT = 'graph_extract';
    public const STAGE_INDEX_VECTOR = 'index_vector';
    public const STAGE_INDEX_GRAPH = 'index_graph';

    public const STATE_PENDING = 'pending';
    public const STATE_QUEUED = 'queued';
    public const STATE_RUNNING = 'running';
    public const STATE_COMPLETED = 'completed';
    public const STATE_FAILED = 'failed';
    public const STATE_SKIPPED = 'skipped';

    protected $fillable = [
        'document_id',
        'stage',
        'state',
        'attempt_count',
        'worker_name',
        'queue_name',
        'last_job_id',
        'started_at',
        'finished_at',
        'error_message',
        'error_context_json',
        'metrics_json',
    ];

    protected $casts = [
        'attempt_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'error_context_json' => 'array',
        'metrics_json' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}

