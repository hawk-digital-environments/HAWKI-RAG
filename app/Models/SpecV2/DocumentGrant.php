<?php
declare(strict_types=1);

namespace App\Models\SpecV2;

use App\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentGrant extends Model
{
    protected $table = 'document_grants';

    protected $fillable = [
        'document_id',
        'group_id',
        'user_identifier',
        'internal_user_id',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id', 'id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id', 'id');
    }

    public function internalUser(): BelongsTo
    {
        return $this->belongsTo(InternalUser::class, 'internal_user_id', 'id');
    }
}
