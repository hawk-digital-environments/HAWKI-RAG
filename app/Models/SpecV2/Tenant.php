<?php
declare(strict_types=1);

namespace App\Models\SpecV2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    protected $table = 'tenants';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'metadata_json',
    ];

    protected $casts = [
        'metadata_json' => 'array',
    ];

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'tenant_id', 'id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class, 'tenant_id', 'id');
    }

    public function heaps(): HasMany
    {
        return $this->hasMany(Heap::class, 'tenant_id', 'id');
    }

    public function internalUsers(): HasMany
    {
        return $this->hasMany(InternalUser::class, 'tenant_id', 'id');
    }
}
