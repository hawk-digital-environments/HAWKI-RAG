<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatasetGrant extends Model
{
    public const PERMISSION_QUERY = 'query';

    public const PRINCIPAL_USER = 'user';

    protected $fillable = [
        'dataset_id',
        'principal_type',
        'principal_id',
        'permission',
    ];

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class, 'dataset_id', 'dataset_id');
    }
}
