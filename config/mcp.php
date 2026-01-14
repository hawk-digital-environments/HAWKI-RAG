<?php

return [
    'enabled' => env('MCP_ENABLED', true),
    'server' => env('MCP_SERVER', 'rawki'),
    'route_enabled' => env('MCP_ROUTE_ENABLED', true),
    'base_url' => env('MCP_BASE_URL', rtrim(env('APP_URL', 'http://localhost'), '/') . '/mcp'),
    'timeout' => env('MCP_TIMEOUT', 30),
    'use_fallback' => env('MCP_USE_FALLBACK', true),
    'log_path' => env('MCP_LOG_PATH', storage_path('app/processRAG_log.txt')),
    'tools' => [
        'rag_query' => env('MCP_TOOL_RAG_QUERY', 'rag-query-tool'),
        'rag_ingest' => env('MCP_TOOL_RAG_INGEST', 'rag-ingest-tool'),
    ],
];
