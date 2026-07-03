<?php
declare(strict_types=1);

namespace App\Models\SpecV2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    protected $table = 'groups';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'owner_application_id',
        'name',
        'description',
        'metadata_json',
    ];

    protected $casts = [
        'metadata_json' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    public function ownerApplication(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'owner_application_id', 'id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class, 'group_id', 'id');
    }
}
