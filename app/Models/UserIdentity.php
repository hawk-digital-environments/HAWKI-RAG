<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\SpecV2\Application;
use App\Models\SpecV2\InternalUser;
use App\Models\SpecV2\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserIdentity extends Model
{
    protected $table = 'user_identities';

    protected $fillable = [
        'user_id',
        'issuer',
        'subject',
        'provider',
        'external_user_id',
        'email',
        'username',
        'claims',
        'tenant_id',
        'application_id',
        'internal_user_id',
    ];

    protected $casts = [
        'claims' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'application_id', 'id');
    }

    public function internalUser(): BelongsTo
    {
        return $this->belongsTo(InternalUser::class, 'internal_user_id', 'id');
    }
}
