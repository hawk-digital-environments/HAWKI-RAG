<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IngestedPage extends Model
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'collection',
        'source_identity_hash',
        'source_identity',
        'canonical_url_hash',
        'canonical_url',
        'source_url',
        'doc_id',
        'source_document_id',
        'content_hash',
        'status',
        'source_id',
        'task_id',
        'job_id',
        'qdrant_collection',
        'neo4j_database',
        'chunks_count',
        'last_seen_at',
        'last_ingested_at',
        'metadata',
    ];

    protected $casts = [
        'chunks_count' => 'integer',
        'last_seen_at' => 'datetime',
        'last_ingested_at' => 'datetime',
        'metadata' => 'array',
    ];
}
