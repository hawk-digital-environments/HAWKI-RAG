<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dataset extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    public $timestamps = false;

    protected $fillable = [
        'dataset_id',
        'name',
        'description',
        'status',
        'qdrant_collection',
        'neo4j_namespace',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(PipelineTask::class, 'dataset_id', 'dataset_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'dataset_id', 'dataset_id');
    }
}
