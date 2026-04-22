<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'document_id',
        'chunk_index',
        'chunk_text',
        'token_count',
        'page_number',
        'section_title',
        'metadata_json',
        'qdrant_point_id',
    ];

    protected $casts = [
        'chunk_index' => 'integer',
        'token_count' => 'integer',
        'page_number' => 'integer',
        'metadata_json' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
}

