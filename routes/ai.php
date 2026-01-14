<?php

use App\Mcp\Servers\RawkiServer;
use Laravel\Mcp\Server\Facades\Mcp;

if (! config('mcp.enabled') || ! config('mcp.route_enabled')) {
    return;
}

Mcp::web(config('mcp.server', 'rawki'), RawkiServer::class);
