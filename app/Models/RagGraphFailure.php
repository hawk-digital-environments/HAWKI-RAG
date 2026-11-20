<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RagGraphFailure extends Model
{
    protected $fillable = [
        'rag_ingestion_artifact_id',
        'job_id',
        'source_id',
        'dataset_id',
        'document_id',
        'error_code',
        'message',
        'context',
        'occurred_at',
    ];

    protected $casts = [
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function ingestionArtifact(): BelongsTo
    {
        return $this->belongsTo(RagIngestionArtifact::class, 'rag_ingestion_artifact_id');
    }
}
