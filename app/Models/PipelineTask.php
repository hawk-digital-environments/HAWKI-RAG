<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineTask extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'task_id',
        'dataset_id',
        'heap_id',
        'status',
        'started_at',
        'finished_at',
        'counters',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'counters' => 'array',
        'metadata' => 'array',
    ];

    public function jobs(): HasMany
    {
        return $this->hasMany(PipelineJob::class, 'task_id', 'task_id');
    }

    public function heap(): BelongsTo
    {
        return $this->belongsTo(Dataset::class, 'dataset_id', 'dataset_id');
    }

    public function dataset(): BelongsTo
    {
        return $this->heap();
    }

    public function heapId(): ?string
    {
        return is_scalar($this->dataset_id) ? (string) $this->dataset_id : null;
    }

    public function getHeapIdAttribute(): ?string
    {
        return $this->heapId();
    }

    public function setHeapIdAttribute(?string $value): void
    {
        $this->attributes['dataset_id'] = $value;
    }
}
