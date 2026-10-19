<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatasetGrantToken extends Model
{
    protected $fillable = [
        'dataset_id',
        'token_hash',
        'expires_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class, 'dataset_id', 'dataset_id');
    }
}
