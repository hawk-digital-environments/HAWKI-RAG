<?php

return [

    /*
    |--------------------------------------------------------------------------
    | RabbitMQ Configuration
    |--------------------------------------------------------------------------
    |
    | Shared RabbitMQ connection settings plus RAG ingestion topology. The RAG
    | worker owns topology, retries, failure publishing, and lifecycle state.
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
        'rag_ingestion' => [
            'events_exchange' => env('RABBITMQ_EVENTS_EXCHANGE', 'pipeline.events'),
            'events_exchange_type' => env('RABBITMQ_EVENTS_EXCHANGE_TYPE', 'direct'),
            'retry_exchange' => env('RABBITMQ_RETRY_EXCHANGE', 'pipeline.retry'),
            'retry_exchange_type' => env('RABBITMQ_RETRY_EXCHANGE_TYPE', 'direct'),
            'failed_exchange' => env('RABBITMQ_FAILED_EXCHANGE', 'pipeline.failed'),
            'failed_exchange_type' => env('RABBITMQ_FAILED_EXCHANGE_TYPE', 'direct'),
            'queue' => env('RABBITMQ_RAG_INGESTION_QUEUE', 'rag_ingestion_jobs'),
            'document_converted_routing_key' => env('RABBITMQ_DOCUMENT_CONVERTED_ROUTING_KEY', 'convert.document.completed'),
            'retry_queue' => env('RABBITMQ_RAG_INGESTION_RETRY_QUEUE', 'rag_ingestion_jobs_retry'),
            'retry_routing_key' => env('RABBITMQ_RAG_INGESTION_RETRY_ROUTING_KEY', 'convert.document.completed.retry'),
            'failed_queue' => env('RABBITMQ_RAG_INGESTION_FAILED_QUEUE', 'rag_ingestion_failed_jobs'),
            'failed_routing_key' => env('RABBITMQ_FAILED_ROUTING_KEY', 'pipeline.failed'),
            'retry_delay_ms' => env('RABBITMQ_RETRY_DELAY_MS', 5000),
            'prefetch_count' => env('RABBITMQ_PREFETCH_COUNT', 1),
            'max_retries' => env('RABBITMQ_MAX_RETRIES', 3),
            'queue_type' => env('RABBITMQ_QUEUE_TYPE', 'quorum'),
            'consumer_tag' => env('RABBITMQ_CONSUMER_TAG', 'hawki-rag-laravel-worker'),
            'shared_storage_root' => env('SHARED_STORAGE_ROOT', '/app/shared'),
            'schema_version' => env('JOB_SCHEMA_VERSION', '1'),
            'service_name' => env('SERVICE_NAME', 'hawki-rag'),
            'provider' => env('RAG_DEFAULT_PROVIDER', 'ollama'),
            'graph' => env('RAG_INGEST_GRAPH', false),
            'bridge_timeout' => env('RAG_INGEST_BRIDGE_TIMEOUT', 3600),
        ],
    ],

];
