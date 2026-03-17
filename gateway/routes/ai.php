<?php

use App\Mcp\Servers\HawkiRagServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web(config('mcp.server', 'mcp/hawki_rag'), HawkiRagServer::class);
    //->middleware('auth:sanctum');
