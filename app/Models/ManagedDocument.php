<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Document\Values\ManagedDocumentId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManagedDocument extends Model
{
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_INDEXED = 'indexed';
    public const STATUS_SKIPPED_UNCHANGED = 'skipped_unchanged';
    public const STATUS_DELETING = 'deleting';
    public const STATUS_DELETED = 'deleted';
    public const STATUS_FAILED = 'failed';

    protected $table = 'managed_documents';

    protected $primaryKey = 'document_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'document_id',
        'dataset_id',
        'display_name',
        'source_type',
        'source_url',
        'source_updated_at',
        'source_checksum_sha256',
        'graph_enabled',
        'status',
        'last_error',
        'latest_source_id',
        'latest_task_id',
        'latest_job_id',
        'latest_document_version',
        'indexed_at',
        'deleted_at',
        'metadata_json',
    ];

    protected $casts = [
        'source_updated_at' => 'datetime',
        'graph_enabled' => 'boolean',
        'indexed_at' => 'datetime',
        'deleted_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function outputs(): HasMany
    {
        return $this->hasMany(ManagedDocumentOutput::class, 'document_id', 'document_id');
    }

    public function documentId(): ManagedDocumentId
    {
        return ManagedDocumentId::fromString((string) $this->getAttribute('document_id'));
    }
}
