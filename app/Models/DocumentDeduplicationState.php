<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentDeduplicationState extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const DECISION_NEW = 'new';

    public const DECISION_UPDATED = 'updated';

    public const DECISION_DUPLICATE = 'duplicate';

    protected $fillable = [
        'scope_key',
        'document_id',
        'completed_content_hash',
        'pending_content_hash',
        'status',
        'decision',
        'claim_token',
        'lease_expires_at',
        'completed_source_id',
        'pending_source_id',
        'task_id',
        'job_id',
        'checked_at',
        'completed_at',
        'metadata',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'lease_expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
    ];
}
