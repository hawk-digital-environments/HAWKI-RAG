<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Communication Service
    |--------------------------------------------------------------------------
    |
    | This option controls the default communication service that will be used
    | for message listening. Supported values: "redis", "rabbitmq"
    |
    */

    'default' => env('COMMUNICATION_SERVICE', 'rabbitmq'),

    /*
    |--------------------------------------------------------------------------
    | Redis Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for Redis Pub/Sub listener.
    | These settings override the default database.redis configuration
    | when used specifically for communication service.
    |
    */

    'redis' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
        'password' => env('REDIS_PASSWORD'),
        'timeout' => 0,
        'reconnect_delay' => 2,
        'max_reconnect_attempts' => 10,
        'default_channel' => env('REDIS_SCRAPE_CHANNEL', 'scrape-events'),
    ],

    /*
    |--------------------------------------------------------------------------
    | RabbitMQ Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for RabbitMQ listener.
    | Requires php-amqplib/php-amqplib package.
    |
    */

    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', 'rabbitmq'),
        'port' => env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'admin'),
        'password' => env('RABBITMQ_PASSWORD', 'admin123'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'exchange' => env('RABBITMQ_EXCHANGE', 'scrape_events'),
        'exchange_type' => env('RABBITMQ_EXCHANGE_TYPE', 'topic'),
        'prefetch_count' => 1,
        'durable' => true,
        'auto_ack' => false,
        'default_queue' => env('RABBITMQ_SCRAPE_QUEUE', 'scrape-events'),
    ],

];
