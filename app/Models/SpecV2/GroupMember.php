<?php
declare(strict_types=1);

namespace App\Models\SpecV2;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupMember extends Model
{
    protected $table = 'group_members';

    protected $fillable = [
        'group_id',
        'user_identifier',
        'internal_user_id',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'group_id', 'id');
    }

    public function internalUser(): BelongsTo
    {
        return $this->belongsTo(InternalUser::class, 'internal_user_id', 'id');
    }
}
