<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JobProcessingState extends Model
{
    protected $table = 'job_processing_state';

    public const STAGE_RAG_INGESTION = 'rag_ingestion';

    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'job_id',
        'stage',
        'source',
        'input_path',
        'output_path',
        'input_checksum',
        'status',
        'retry_count',
        'max_retries',
        'first_received_at',
        'last_received_at',
        'processing_started_at',
        'completed_at',
        'failed_at',
        'error_type',
        'error_message',
        'trace_id',
    ];

    protected $casts = [
        'retry_count' => 'integer',
        'max_retries' => 'integer',
        'first_received_at' => 'datetime',
        'last_received_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];
}

