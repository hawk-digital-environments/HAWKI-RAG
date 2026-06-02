<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PipelineTask extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_CANCEL_REQUESTED = 'cancel_requested';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'task_id',
        'dataset_id',
        'profile_id',
        'sitemap_url',
        'sitemap_path',
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

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            self::STATUS_CANCELLED,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
        ], true);
    }
}
