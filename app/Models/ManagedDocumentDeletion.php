<?php

declare(strict_types=1);

namespace App\Models;

class ManagedDocumentDeletion extends \Illuminate\Database\Eloquent\Model
{
    protected $primaryKey = 'operation_id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];
    protected $casts = ['workflow_input' => 'array', 'results' => 'array', 'replacement_result' => 'array'];
}
