<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthorizationIdentity extends Model
{
    protected $fillable = [
        'user_id',
        'issuer',
        'subject',
        'provider',
        'external_user_id',
        'email',
        'username',
        'claims',
    ];

    protected $casts = [
        'claims' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
