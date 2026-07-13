<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Document\Values\ManagedDocumentId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManagedDocumentOutput extends Model
{
    protected $table = 'assistant_document_outputs';

    protected $fillable = [
        'assistant_document_id',
        'bridge_document_id',
        'qdrant_collection',
        'neo4j_namespace',
        'source_id',
        'task_id',
        'job_id',
        'content_hash',
        'chunk_count',
        'status',
        'active',
        'indexed_at',
        'deleted_at',
        'metadata_json',
    ];

    protected $casts = [
        'chunk_count' => 'integer',
        'active' => 'boolean',
        'indexed_at' => 'datetime',
        'deleted_at' => 'datetime',
        'metadata_json' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(ManagedDocument::class, 'assistant_document_id', 'assistant_document_id');
    }

    public function documentId(): ManagedDocumentId
    {
        return ManagedDocumentId::fromString((string) $this->getAttribute('assistant_document_id'));
    }
}
