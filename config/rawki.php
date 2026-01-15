<?php

return [


    'base_url' => env('RAWKI_BASE_URL', 'http://rawki_bridge:8000'),
    'shared_root' => env('RAWKI_SHARED_ROOT', storage_path('app/public')),
    'ingest_status_path' => env('RAWKI_INGEST_STATUS_PATH', storage_path('logs/ingest_status.json')),
    'ingest_log_path' => env('RAWKI_INGEST_LOG_PATH', storage_path('logs/ingest_progress.log')),
    'rag_api_url' => env('RAWKI_RAG_API_URL', 'http://raganything_api:8003'),
    'pipeline_status_path' => env('RAWKI_PIPELINE_STATUS_PATH', storage_path('logs/pipeline_status.json')),

];
