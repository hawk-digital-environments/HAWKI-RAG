<?php
declare(strict_types=1);

namespace App\Models\SpecV2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    public const PERMISSION_READS = 'reads';
    public const PERMISSION_READS_ALL_APPS = 'reads-all-apps';
    public const PERMISSION_READS_FEDERATED = 'reads-federated';
    public const PERMISSION_READS_PROTECTED = 'reads-protected';

    protected $table = 'applications';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'tenant_id',
        'name',
        'description',
        'permissions',
        'token_hash',
        'metadata_json',
    ];

    protected $casts = [
        'permissions' => 'array',
        'metadata_json' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    public function heaps(): HasMany
    {
        return $this->hasMany(Heap::class, 'owner_application_id', 'id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class, 'owner_application_id', 'id');
    }

    /**
     * @return list<string>
     */
    public static function allowedPermissions(): array
    {
        return [
            self::PERMISSION_READS,
            self::PERMISSION_READS_ALL_APPS,
            self::PERMISSION_READS_FEDERATED,
            self::PERMISSION_READS_PROTECTED,
        ];
    }
}
