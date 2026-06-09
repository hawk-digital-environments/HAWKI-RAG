<?php

$pipelineRoot = rtrim((string) env('HAWKI_RAG_PIPELINE_ROOT', '/app/shared'), DIRECTORY_SEPARATOR);
$crawledDataRoot = rtrim((string) env('HAWKI_RAG_CRAWLED_DATA_ROOT', env('DEFAULT_CRAWLED_ROOT', $pipelineRoot)), DIRECTORY_SEPARATOR);

return [


    'base_url' => env('HAWKI_RAG_BASE_URL', 'http://hawki_rag_bridge:8000'),
    'qdrant_http_url' => env('QDRANT_HTTP_URL', 'http://qdrant:6333'),
    'neo4j_http_url' => env('NEO4J_HTTP_URL', 'http://hawki_rag_neo4j:7474'),
    'neo4j_user' => env('NEO4J_USER', 'neo4j'),
    'neo4j_password' => env('NEO4J_PASSWORD', ''),
    'neo4j_database' => env('NEO4J_DATABASE', 'neo4j'),
    'graph_snapshot_path' => env('HAWKI_RAG_GRAPH_SNAPSHOT_PATH', storage_path('app/graph_snapshots')),
    'graph_visualization_path' => env('HAWKI_RAG_GRAPH_VISUALIZATION_PATH', public_path('neo4j_graph_visualization.json')),
    'pipeline_root' => $pipelineRoot,
    'shared_root' => $pipelineRoot,
    'crawled_data_root' => $crawledDataRoot,
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
    'rag_api_url' => env('HAWKI_RAG_API_URL', env('HAWKI_RAG_BRIDGE_URL', 'http://hawki_rag_bridge:8000')),
    'ingest_summary_paths' => [
        env('HAWKI_RAG_INGEST_SUMMARY_PUBLIC_PATH', public_path('ingest_summary.json')),
        env('HAWKI_RAG_INGEST_SUMMARY_STORAGE_PATH', storage_path('logs/ingest_summary.json')),
    ],
    'graph_preview_paths' => [
        env('HAWKI_RAG_GRAPH_PREVIEW_PATH', public_path('ingest_graph_preview.json')),
    ],
    'graph_failures_path' => env('HAWKI_RAG_GRAPH_FAILURES_PATH', public_path('ingest_graph_failures.jsonl')),
    'embedding_models' => array_values(array_filter(array_map('trim', explode(',', env('OLLAMA_EMBED_MODEL', 'bge-m3'))))),
    'embedding_default' => env('OLLAMA_EMBED_MODEL', 'bge-m3'),
    'graph_models' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('HAWKI_RAG_GRAPH_MODELS', env('GRAPH_OLLAMA_RAG_MODEL', 'llama3.1:8b')))
    ))),
    'graph_default' => env('HAWKI_RAG_GRAPH_MODEL', env('GRAPH_OLLAMA_RAG_MODEL', 'llama3.1:8b')),
    'qdrant_distance' => env('QDRANT_DISTANCE', 'Cosine'),
    'chunk_size' => (int) env('CHUNK_SIZE', 1200),
    'chunk_overlap_size' => (int) env('CHUNK_OVERLAP_SIZE', 250),
    'ingest_batch_size' => (int) env('INGEST_BATCH_SIZE', 64),
    'graph_engine' => env('GRAPH_ENGINE', 'raganything'),
    'graph_provider' => env('GRAPH_PROVIDER', 'ollama'),
    'graph_doc_max_chars' => (int) env('GRAPH_DOC_MAX_CHARS', 0),
    'graph_doc_max_chunks' => (int) env('GRAPH_DOC_MAX_CHUNKS', 0),
    'graph_reset_cache_per_doc' => filter_var(env('GRAPH_RESET_CACHE_PER_DOC', true), FILTER_VALIDATE_BOOLEAN),
    'pipeline_status_path' => env('HAWKI_RAG_PIPELINE_STATUS_PATH', storage_path('logs/pipeline_status.json')),
    'pipeline_proof_root' => env('HAWKI_RAG_PIPELINE_PROOF_ROOT', storage_path('logs/pipeline-proofs')),
    'pipeline_proof_log_files' => [
        storage_path('logs/comm_logs.json'),
        storage_path('logs/laravel.log'),
    ],
    'pipeline_proof_log_globs' => [
        storage_path('logs/laravel-*.log'),
    ],
    'hawki_rag_bridge_url' => env('HAWKI_RAG_BRIDGE_URL', 'http://hawki_rag_bridge:8000'),
    'pipeline_automation' => filter_var(env('HAWKI_RAG_PIPELINE_AUTOMATION', false), FILTER_VALIDATE_BOOLEAN),
    'convert_existing_mode' => env('HAWKI_RAG_CONVERT_EXISTING_MODE', 'continue'),
    'ingest_resume_mode' => env('HAWKI_RAG_INGEST_RESUME_MODE', 'resume'),
    'pipeline_demo_urls' => env('PIPELINE_DEMO_URLS', ''),
    'docker_project_path' => env('DOCKER_PROJECT_PATH', ''),
    'virtual_path' => env('VIRTUAL_PATH', ''),
];
