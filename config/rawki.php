<?php

return [


    'base_url' => env('RAWKI_BASE_URL', 'http://rawki_bridge:8000'),
    'shared_root' => env('RAWKI_SHARED_ROOT', storage_path('app/public')),
    'ingest_status_path' => env('RAWKI_INGEST_STATUS_PATH', storage_path('logs/ingest_status.json')),
    'ingest_log_path' => env('RAWKI_INGEST_LOG_PATH', storage_path('logs/ingest_progress.log')),
    'rag_api_url' => env('RAWKI_RAG_API_URL', 'http://raganything_api:8003'),
    'embedding_models' => array_values(array_filter(array_map('trim', explode(',', env('RAWKI_EMBED_MODELS', env('OLLAMA_EMBED_MODEL', 'bge-m3')))))),
    'embedding_default' => env('RAWKI_EMBED_MODEL', env('OLLAMA_EMBED_MODEL', 'bge-m3')),
    'pipeline_status_path' => env('RAWKI_PIPELINE_STATUS_PATH', storage_path('logs/pipeline_status.json')),
    'rawki_bridge_url' => env('RAWKI_BRIDGE_URL', 'http://rawki_bridge:8000'),
];
