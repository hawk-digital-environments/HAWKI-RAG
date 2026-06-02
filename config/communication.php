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
        'pipeline_events' => [
            'enabled' => env('RABBITMQ_PIPELINE_EVENTS_ENABLED', true),
            'exchange' => env('RABBITMQ_PIPELINE_EVENTS_EXCHANGE', env('RABBITMQ_EVENTS_EXCHANGE', 'pipeline.events')),
            'exchange_type' => env('RABBITMQ_PIPELINE_EVENTS_EXCHANGE_TYPE', env('RABBITMQ_EVENTS_EXCHANGE_TYPE', 'direct')),
            'retry_exchange' => env('RABBITMQ_PIPELINE_RETRY_EXCHANGE', env('RABBITMQ_RETRY_EXCHANGE', 'pipeline.retry')),
            'retry_exchange_type' => env('RABBITMQ_PIPELINE_RETRY_EXCHANGE_TYPE', env('RABBITMQ_RETRY_EXCHANGE_TYPE', 'direct')),
            'failed_exchange' => env('RABBITMQ_PIPELINE_FAILED_EXCHANGE', env('RABBITMQ_FAILED_EXCHANGE', 'pipeline.failed')),
            'failed_exchange_type' => env('RABBITMQ_PIPELINE_FAILED_EXCHANGE_TYPE', env('RABBITMQ_FAILED_EXCHANGE_TYPE', 'direct')),
            'retry_delay_ms' => env('RABBITMQ_PIPELINE_RETRY_DELAY_MS', env('RABBITMQ_RETRY_DELAY_MS', 5000)),
            'max_retries' => env('RABBITMQ_PIPELINE_MAX_RETRIES', env('RABBITMQ_MAX_RETRIES', 3)),
            'prefetch_count' => env('RABBITMQ_PIPELINE_PREFETCH_COUNT', env('RABBITMQ_PREFETCH_COUNT', 1)),
            'queue_type' => env('RABBITMQ_PIPELINE_QUEUE_TYPE', env('RABBITMQ_QUEUE_TYPE', 'quorum')),
            'task_started_routing_key' => env('RABBITMQ_TASK_STARTED_ROUTING_KEY', 'task.started'),
            'task_cancel_requested_routing_key' => env('RABBITMQ_TASK_CANCEL_REQUESTED_ROUTING_KEY', 'task.cancel_requested'),
            'task_resumed_routing_key' => env('RABBITMQ_TASK_RESUMED_ROUTING_KEY', 'task.resumed'),
            'task_retry_requested_routing_key' => env('RABBITMQ_TASK_RETRY_REQUESTED_ROUTING_KEY', 'task.retry_requested'),
            'scrape_job_queued_routing_key' => env('RABBITMQ_SCRAPE_JOB_QUEUED_ROUTING_KEY', 'scrape.requested'),
            'failed_routing_key' => env('RABBITMQ_PIPELINE_FAILED_ROUTING_KEY', 'job.failed'),
            'events' => [
                'task_started' => env('RABBITMQ_TASK_STARTED_ROUTING_KEY', 'task.started'),
                'page_discovered' => env('RABBITMQ_PAGE_DISCOVERED_ROUTING_KEY', 'page.discovered'),
                'scrape_requested' => env('RABBITMQ_SCRAPE_REQUESTED_ROUTING_KEY', 'scrape.requested'),
                'page_scraped' => env('RABBITMQ_PAGE_SCRAPED_ROUTING_KEY', 'page.scraped'),
                'file_discovered' => env('RABBITMQ_FILE_DISCOVERED_ROUTING_KEY', 'file.discovered'),
                'convert_requested' => env('RABBITMQ_CONVERT_REQUESTED_ROUTING_KEY', 'convert.requested'),
                'file_converted' => env('RABBITMQ_FILE_CONVERTED_ROUTING_KEY', 'file.converted'),
                'ingest_requested' => env('RABBITMQ_INGEST_REQUESTED_ROUTING_KEY', 'ingest.requested'),
                'content_ingested' => env('RABBITMQ_CONTENT_INGESTED_ROUTING_KEY', 'content.ingested'),
                'graph_updated' => env('RABBITMQ_GRAPH_UPDATED_ROUTING_KEY', 'graph.updated'),
                'job_failed' => env('RABBITMQ_JOB_FAILED_ROUTING_KEY', 'job.failed'),
            ],
            'workers' => [
                'scraper' => [
                    'queue' => env('RABBITMQ_PIPELINE_SCRAPER_QUEUE', 'pipeline_scraper_events'),
                    'consumer_tag' => env('RABBITMQ_PIPELINE_SCRAPER_CONSUMER_TAG', 'hawki-rag-scraper-events'),
                    'listen' => ['task.started', 'scrape.requested', 'page.discovered'],
                ],
                'converter' => [
                    'queue' => env('RABBITMQ_PIPELINE_CONVERTER_QUEUE', 'pipeline_converter_events'),
                    'consumer_tag' => env('RABBITMQ_PIPELINE_CONVERTER_CONSUMER_TAG', 'hawki-rag-converter-events'),
                    'listen' => ['file.discovered', 'convert.requested'],
                ],
                'ingestion' => [
                    'queue' => env('RABBITMQ_PIPELINE_INGESTION_QUEUE', 'pipeline_ingestion_events'),
                    'consumer_tag' => env('RABBITMQ_PIPELINE_INGESTION_CONSUMER_TAG', 'hawki-rag-ingestion-events'),
                    'listen' => ['page.scraped', 'file.converted', 'ingest.requested'],
                ],
            ],
        ],
    ],

];
