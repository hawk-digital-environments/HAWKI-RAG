<?php

use App\Mcp\Servers\HawkiRagServer;
use Laravel\Mcp\Server\Facades\Mcp;

if (! config('mcp.enabled') || ! config('mcp.route_enabled')) {
    return;
}

Mcp::web(config('mcp.server', 'hawki_rag'), HawkiRagServer::class);
