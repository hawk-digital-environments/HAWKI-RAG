<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Temporal RAG Orchestration
    |--------------------------------------------------------------------------
    |
    | Laravel stores metadata and delegates Temporal client operations to the
    | Python bridge. Python starts workflows and runs activities/workers.
    |
    */

    'enabled' => env('HAWKI_RAG_TEMPORAL_ENABLED', true),
    'address' => env('TEMPORAL_ADDRESS', 'temporal:7233'),
    'namespace' => env('TEMPORAL_NAMESPACE', 'default'),
    'identity' => env('TEMPORAL_CLIENT_IDENTITY', 'hawki-rag-laravel'),
    'bridge_timeout' => env('HAWKI_RAG_BRIDGE_TIMEOUT', 30),

    'workflow' => [
        'type' => env('TEMPORAL_INGEST_WORKFLOW_TYPE', 'IngestSourceWorkflow'),
        'execution_timeout' => env('TEMPORAL_WORKFLOW_EXECUTION_TIMEOUT', '86400 seconds'),
        'run_timeout' => env('TEMPORAL_WORKFLOW_RUN_TIMEOUT', '21600 seconds'),
        'task_timeout' => env('TEMPORAL_WORKFLOW_TASK_TIMEOUT', '30 seconds'),
    ],

    'task_queues' => [
        'workflow' => env('TEMPORAL_RAG_WORKFLOW_TASK_QUEUE', 'rag-workflow-task-queue'),
        'scraper' => env('TEMPORAL_RAG_SCRAPER_TASK_QUEUE', 'rag-scraper-task-queue'),
        'converter' => env('TEMPORAL_RAG_CONVERTER_TASK_QUEUE', 'rag-converter-task-queue'),
        'ingestion' => env('TEMPORAL_RAG_INGESTION_TASK_QUEUE', 'rag-ingestion-task-queue'),
    ],

    'storage' => [
        'mode' => env('HAWKI_RAG_STORAGE_MODE', 'shared'),
        'shared_root' => env('HAWKI_RAG_TEMPORAL_SHARED_ROOT', '/shared'),
        'shared_storage_web_user' => env('PIPELINE_SHARED_STORAGE_WEB_USER', env('PHP_FPM_USER', 'www-data')),
        'object_prefix' => env('HAWKI_RAG_OBJECT_STORAGE_PREFIX', 's3://hawki-rag'),
    ],

    'ingestion' => [
        'provider' => env('RAG_DEFAULT_PROVIDER', 'ollama'),
        'graph' => env('RAG_INGEST_GRAPH', false),
        'bridge_timeout' => env('RAG_INGEST_BRIDGE_TIMEOUT', 3600),
    ],

    'refresh_cadences' => [
        'daily' => env('TEMPORAL_RAG_DAILY_CRON', '0 2 * * *'),
        'weekly' => env('TEMPORAL_RAG_WEEKLY_CRON', '0 2 * * 0'),
        'monthly' => env('TEMPORAL_RAG_MONTHLY_CRON', '0 2 1 * *'),
    ],

    'external_services' => [
        'scraper_url' => env('EXTERNAL_SCRAPER_URL', env('CUSTOM_CRAWLER_URL', 'http://crawl4ai-service')),
        'scraper_start_path' => env('EXTERNAL_SCRAPER_START_PATH', '/crawl'),
        'scraper_status_path' => env('EXTERNAL_SCRAPER_STATUS_PATH', '/status/{job_id}'),
        'scraper_token' => env('EXTERNAL_SCRAPER_TOKEN', env('CUSTOM_CRAWLER_API_KEY', '')),
        'converter_url' => env('EXTERNAL_CONVERTER_URL', env('FILE_CONVERTER_BASE_URL', 'http://file-converter:8000')),
        'converter_start_path' => env('EXTERNAL_CONVERTER_START_PATH', '/extract'),
        'converter_status_path' => env('EXTERNAL_CONVERTER_STATUS_PATH', ''),
        'converter_token' => env('EXTERNAL_CONVERTER_TOKEN', env('FILE_CONVERTER_TOKEN', '')),
    ],
];
