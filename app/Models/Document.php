<?php

namespace App\Models;

use App\Models\SpecV2\Corpus;
use App\Models\SpecV2\Heap;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'external_id',
        'dataset_id',
        'heap_id',
        'corpus_id',
        'collection',
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

    public function heap(): BelongsTo
    {
        return $this->belongsTo(Heap::class, 'dataset_id', 'dataset_id');
    }

    public function heapId(): ?string
    {
        return is_scalar($this->dataset_id) ? (string) $this->dataset_id : null;
    }

    public function heapStorageColumn(): string
    {
        return 'dataset_id';
    }

    public function moveToHeap(string|Heap $heap): void
    {
        $this->attributes['dataset_id'] = $heap instanceof Heap ? $heap->heapId() : $heap;
    }

    public function getHeapIdAttribute(): ?string
    {
        return $this->heapId();
    }

    public function setHeapIdAttribute(?string $value): void
    {
        $this->attributes['dataset_id'] = $value;
    }

    public function corpus(): BelongsTo
    {
        return $this->belongsTo(Corpus::class, 'corpus_id', 'id');
    }
}
