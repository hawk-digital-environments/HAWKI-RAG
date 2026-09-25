<?php

declare(strict_types=1);

use App\Mcp\Servers\HawkiKnowledgeBaseServer;
use App\Mcp\Servers\HawkiRagServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('mcp/hawki-knowldgeBase', HawkiKnowledgeBaseServer::class)
    ->middleware(['auth:sanctum', 'abilities:query', 'can:access-query-principal']);

Mcp::web(config('mcp.server', 'mcp/hawki_rag'), HawkiRagServer::class)
    ->middleware(['auth:sanctum', 'abilities:query', 'can:access-query-principal', 'throttle:hawki-api']);
