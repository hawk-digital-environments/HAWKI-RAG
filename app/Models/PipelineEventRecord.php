<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PipelineEventRecord extends Model
{
    protected $table = 'pipeline_events';

    public $timestamps = false;

    protected $fillable = [
        'task_id',
        'job_id',
        'event_type',
        'source',
        'message',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];
}
