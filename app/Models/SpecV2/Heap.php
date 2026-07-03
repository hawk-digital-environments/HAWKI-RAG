<?php
declare(strict_types=1);

namespace App\Models\SpecV2;

use App\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Heap extends Model
{
    public const VISIBILITY_DISCOVERABLE = 'discoverable';
    public const VISIBILITY_HIDDEN = 'hidden';

    protected $table = 'datasets';

    protected $primaryKey = 'dataset_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'dataset_id',
        'tenant_id',
        'owner_application_id',
        'name',
        'description',
        'status',
        'visibility',
        'protected',
        'metadata_json',
        'qdrant_collection',
        'neo4j_namespace',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'protected' => 'boolean',
        'metadata_json' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id', 'id');
    }

    public function ownerApplication(): BelongsTo
    {
        return $this->belongsTo(Application::class, 'owner_application_id', 'id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'dataset_id', 'dataset_id');
    }
}
