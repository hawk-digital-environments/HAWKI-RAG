<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthorizationPermissionEvent extends Model
{
    protected $fillable = [
        'provider',
        'external_user_id',
        'course_id',
        'role',
        'document_id',
        'source_updated_at',
        'payload',
    ];

    protected $casts = [
        'source_updated_at' => 'datetime',
        'payload' => 'array',
    ];
}
