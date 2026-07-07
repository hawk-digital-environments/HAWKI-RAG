<?php

namespace App\Models;

use App\Models\SpecV2\Application;
use App\Models\SpecV2\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dataset extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';
    public const VISIBILITY_DISCOVERABLE = 'discoverable';
    public const VISIBILITY_HIDDEN = 'hidden';

    public $timestamps = false;

    protected $fillable = [
        'dataset_id',
        'tenant_id',
        'owner_application_id',
        'name',
        'description',
        'status',
        'visibility',
        'protected',
        'metadata_json',
        'qdrant_collection',
        'neo4j_namespace',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'protected' => 'boolean',
        'metadata_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(PipelineTask::class, 'dataset_id', 'dataset_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'dataset_id', 'dataset_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    public function ownerApplication(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'owner_application_id', 'id');
    }

    public function heapId(): string
    {
        return (string) $this->dataset_id;
    }

    public function getHeapIdAttribute(): string
    {
        return $this->heapId();
    }
}
