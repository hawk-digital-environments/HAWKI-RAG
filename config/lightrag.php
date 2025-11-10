<?php


return [

    'base_url' => rtrim(env('LIGHTRAG_URL', 'http://lightrag:8000'), '/'),
    // Allow switching to the official LightRAG server endpoints via env
    'healthPath' => env('LIGHTRAG_HEALTH_PATH', '/health'),
    'ingestPath' => env('LIGHTRAG_INGEST_PATH', '/ingest'),
    'queryPath' => env('LIGHTRAG_QUERY_PATH', '/query'),
    'graphFromTextPath' => env('LIGHTRAG_GRAPH_TEXT_PATH', '/graph/from-text')
];
