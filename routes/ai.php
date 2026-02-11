<?php

use App\Mcp\Servers\HawkiRagServer;
use Laravel\Mcp\Server\Facades\Mcp;

Mcp::web(config('mcp.server', 'hawki_rag'), HawkiRagServer::class)
    ->middleware('auth:sanctum');
