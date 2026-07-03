<?php
declare(strict_types=1);

namespace App\Models\SpecV2;

use App\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Corpus extends Model
{
    protected $table = 'corpora';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'content',
        'reference_count',
        'metadata_json',
    ];

    protected $casts = [
        'reference_count' => 'integer',
        'metadata_json' => 'array',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'corpus_id', 'id');
    }
}
