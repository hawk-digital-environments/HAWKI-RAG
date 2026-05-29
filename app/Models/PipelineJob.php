<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineJob extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_PARTIAL = 'partial';

    protected $fillable = [
        'job_id',
        'status',
        'current_stage',
        'dataset_path',
        'source_url',
        'label',
        'total_documents',
        'processed_documents',
        'failed_documents',
        'skipped_documents',
        'metadata',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'total_documents' => 'integer',
        'processed_documents' => 'integer',
        'failed_documents' => 'integer',
        'skipped_documents' => 'integer',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function stages(): HasMany
    {
        return $this->hasMany(PipelineStageState::class);
    }
}
