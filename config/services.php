<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'qdrant' => [
        'host' => env('QDRANT_HOST'),
        'port' => env('QDRANT_PORT'),
        'collection' => env('QDRANT_COLLECTION'),
    ],
    'file_converter' => [
        'url'             => env('FILE_CONVERTER_URL', 'http://127.0.0.1:8005/extract'),
        'timeout'         => (int) env('FILE_CONVERTER_TIMEOUT', 300),
        'connect_timeout' => (int) env('FILE_CONVERTER_CONNECT_TIMEOUT', 10),
        'retries'         => (int) env('FILE_CONVERTER_RETRIES', 3),
        'retry_delay_ms'  => (int) env('FILE_CONVERTER_RETRY_DELAY_MS', 1500),
    ],

    'rawki' => [
        'base_url' => env('RAWKI_BASE_URL', 'http://rawki_bridge:8000'),
    ],
];
