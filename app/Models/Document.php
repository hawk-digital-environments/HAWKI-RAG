<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    use HasUuids;

    public const SOURCE_UPLOAD = 'upload';
    public const SOURCE_SCRAPE = 'scrape';
    public const SOURCE_API = 'api';
    public const SOURCE_MANUAL = 'manual';

    public const STATUS_CREATED = 'created';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_ARCHIVED = 'archived';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'application_id',
        'heap_id',
        'external_id',
        'source_type',
        'source_url',
        'original_filename',
        'storage_path',
        'mime_type',
        'file_size',
        'checksum_sha256',
        'language',
        'title',
        'author',
        'published_at',
        'metadata_json',
        'status',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'published_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function processingStates(): HasMany
    {
        return $this->hasMany(DocumentProcessingState::class, 'document_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class, 'document_id');
    }
}

