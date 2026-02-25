<?php

return [


    'base_url' => env('HAWKI_RAG_BASE_URL', 'http://hawki_rag_bridge:8000'),
    'qdrant_http_url' => env('QDRANT_HTTP_URL', 'http://qdrant:6333'),
    'neo4j_http_url' => env('NEO4J_HTTP_URL', 'http://hawki_rag_neo4j:7474'),
    'neo4j_user' => env('NEO4J_USER', 'neo4j'),
    'neo4j_password' => env('NEO4J_PASSWORD', ''),
    'shared_root' => env('HAWKI_RAG_SHARED_ROOT', storage_path('app/public')),
    'ingest_status_path' => env('HAWKI_RAG_INGEST_STATUS_PATH', storage_path('logs/ingest_status.json')),
    // Full ingest log (append-only).
    'ingest_log_path' => env('HAWKI_RAG_INGEST_LOG_PATH', storage_path('logs/ingest_progress_full.log')),
    // Cache log used by UI (cleared via "Clear ingest logs").
    'ingest_log_cache_path' => env('HAWKI_RAG_INGEST_LOG_CACHE_PATH', storage_path('logs/ingest_progress_cache.log')),
    'ingest_status_path_neo4j' => env('HAWKI_RAG_INGEST_STATUS_PATH_NEO4J', storage_path('logs/ingest_status_neo4j.json')),
    // Full ingest log (append-only).
    'ingest_log_path_neo4j' => env('HAWKI_RAG_INGEST_LOG_PATH_NEO4J', storage_path('logs/ingest_progress_neo4j_full.log')),
    // Cache log used by UI (cleared via "Clear ingest logs").
    'ingest_log_cache_path_neo4j' => env('HAWKI_RAG_INGEST_LOG_CACHE_PATH_NEO4J', storage_path('logs/ingest_progress_neo4j_cache.log')),
    'rag_api_url' => env('HAWKI_RAG_API_URL', 'http://raganything_api_gpu:8003'),
    'embedding_models' => array_values(array_filter(array_map('trim', explode(',', env('OLLAMA_EMBED_MODEL', 'bge-m3'))))),
    'embedding_default' => env('OLLAMA_EMBED_MODEL', 'bge-m3'),
    'graph_models' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('HAWKI_RAG_GRAPH_MODELS', env('GRAPH_OLLAMA_RAG_MODEL', 'llama3.2:3b')))
    ))),
    'graph_default' => env('HAWKI_RAG_GRAPH_MODEL', env('GRAPH_OLLAMA_RAG_MODEL', 'llama3.2:3b')),
    'pipeline_status_path' => env('HAWKI_RAG_PIPELINE_STATUS_PATH', storage_path('logs/pipeline_status.json')),
    'hawki_rag_bridge_url' => env('HAWKI_RAG_BRIDGE_URL', 'http://hawki_rag_bridge:8000'),
];
