<?php

return [

    /*
    |--------------------------------------------------------------------------
    | RabbitMQ Configuration
    |--------------------------------------------------------------------------
    |
    | Shared RabbitMQ connection settings plus the MVP pipeline event topology.
    | Laravel owns orchestration and RabbitMQ carries worker events.
    |
    */

    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', 'rabbitmq'),
        'port' => env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
        'heartbeat' => env('RABBITMQ_HEARTBEAT', 30),
        'connection_timeout' => env('RABBITMQ_CONNECTION_TIMEOUT', 30),
        'read_write_timeout' => env('RABBITMQ_READ_WRITE_TIMEOUT', 30),
        'management_url' => env('RABBITMQ_MANAGEMENT_URL', 'http://rabbitmq:15672'),
        'pipeline_ingestion' => [
            'shared_storage_root' => env('SHARED_STORAGE_ROOT', '/app/shared'),
            'shared_storage_web_user' => env('PIPELINE_SHARED_STORAGE_WEB_USER', env('PHP_FPM_USER', 'www-data')),
            'schema_version' => env('JOB_SCHEMA_VERSION', '1'),
            'service_name' => env('SERVICE_NAME', 'hawki-rag'),
            'provider' => env('RAG_DEFAULT_PROVIDER', 'ollama'),
            'graph' => env('RAG_INGEST_GRAPH', false),
            'bridge_timeout' => env('RAG_INGEST_BRIDGE_TIMEOUT', 3600),
        ],
        'pipeline_events' => [
            'enabled' => env('RABBITMQ_PIPELINE_EVENTS_ENABLED', true),
            'exchange' => env('RABBITMQ_PIPELINE_EVENTS_EXCHANGE', env('RABBITMQ_EVENTS_EXCHANGE', 'pipeline.events')),
            'exchange_type' => env('RABBITMQ_PIPELINE_EVENTS_EXCHANGE_TYPE', env('RABBITMQ_EVENTS_EXCHANGE_TYPE', 'direct')),
            'retry_exchange' => env('RABBITMQ_PIPELINE_RETRY_EXCHANGE', env('RABBITMQ_RETRY_EXCHANGE', 'pipeline.retry')),
            'retry_exchange_type' => env('RABBITMQ_PIPELINE_RETRY_EXCHANGE_TYPE', env('RABBITMQ_RETRY_EXCHANGE_TYPE', 'direct')),
            'failed_exchange' => env('RABBITMQ_PIPELINE_FAILED_EXCHANGE', env('RABBITMQ_FAILED_EXCHANGE', 'pipeline.failures')),
            'failed_exchange_type' => env('RABBITMQ_PIPELINE_FAILED_EXCHANGE_TYPE', env('RABBITMQ_FAILED_EXCHANGE_TYPE', 'direct')),
            'retry_delay_ms' => env('RABBITMQ_PIPELINE_RETRY_DELAY_MS', env('RABBITMQ_RETRY_DELAY_MS', 5000)),
            'max_retries' => env('RABBITMQ_PIPELINE_MAX_RETRIES', env('RABBITMQ_MAX_RETRIES', 3)),
            'prefetch_count' => env('RABBITMQ_PIPELINE_PREFETCH_COUNT', env('RABBITMQ_PREFETCH_COUNT', 1)),
            'queue_type' => env('RABBITMQ_PIPELINE_QUEUE_TYPE', env('RABBITMQ_QUEUE_TYPE', 'quorum')),
            'schema_version' => env('JOB_SCHEMA_VERSION', '1'),
            'failed_queue' => env('RABBITMQ_PIPELINE_FAILED_QUEUE', 'pipeline_failed_events'),
            'failed_routing_key' => 'job.failed',
            'events' => [
                'scrape_requested' => 'scrape.requested',
                'page_scraped' => 'page.scraped',
                'file_discovered' => 'file.discovered',
                'file_converted' => 'file.converted',
                'content_ingested' => 'content.ingested',
                'job_failed' => 'job.failed',
            ],
            'workers' => [
                'scraper' => [
                    'queue' => env('RABBITMQ_PIPELINE_SCRAPER_QUEUE', 'pipeline_scraper_events'),
                    'consumer_tag' => env('RABBITMQ_PIPELINE_SCRAPER_CONSUMER_TAG', 'hawki-rag-scraper-events'),
                    'listen' => ['scrape.requested'],
                ],
                'converter' => [
                    'queue' => env('RABBITMQ_PIPELINE_CONVERTER_QUEUE', 'pipeline_converter_events'),
                    'consumer_tag' => env('RABBITMQ_PIPELINE_CONVERTER_CONSUMER_TAG', 'hawki-rag-converter-events'),
                    'listen' => ['file.discovered'],
                ],
                'ingestion' => [
                    'queue' => env('RABBITMQ_PIPELINE_INGESTION_QUEUE', 'pipeline_ingestion_events'),
                    'consumer_tag' => env('RABBITMQ_PIPELINE_INGESTION_CONSUMER_TAG', 'hawki-rag-ingestion-events'),
                    'listen' => ['page.scraped', 'file.converted'],
                ],
            ],
        ],
    ],

];
