<?php
declare(strict_types=1);

namespace App\Models\SpecV2;

use App\Models\AuthorizationIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InternalUser extends Model
{
    protected $table = 'internal_users';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'metadata_json',
    ];

    protected $casts = [
        'metadata_json' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    public function identities(): HasMany
    {
        return $this->hasMany(AuthorizationIdentity::class, 'internal_user_id', 'id');
    }

    public function groupMembers(): HasMany
    {
        return $this->hasMany(GroupMember::class, 'internal_user_id', 'id');
    }
}
