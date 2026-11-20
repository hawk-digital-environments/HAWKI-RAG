<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ManagedDocumentDeletion extends Model
{
    protected $primaryKey = 'operation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['workflow_input' => 'array', 'results' => 'array', 'replacement_result' => 'array', 'replacement' => 'array', 'attempts' => 'array'];
}
