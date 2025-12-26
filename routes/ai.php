<?php

use App\Mcp\Servers\RawkiServer;
use Laravel\Mcp\Server\Facades\Mcp;

Mcp::web('rawki', RawkiServer::class);
