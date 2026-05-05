<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RabbitMQQueueState extends Model
{
    protected $table = 'rabbitmq_queue_state';

    protected $primaryKey = 'queue_name';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = [
        'queue_name',
        'messages_ready',
        'messages_unacknowledged',
        'messages_total',
        'consumers',
        'state',
        'sampled_at',
        'updated_at',
    ];

    protected $casts = [
        'messages_ready' => 'integer',
        'messages_unacknowledged' => 'integer',
        'messages_total' => 'integer',
        'consumers' => 'integer',
        'sampled_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
