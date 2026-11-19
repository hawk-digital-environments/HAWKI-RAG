<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\WebSearchTool;
use Laravel\Mcp\Server;

class HawkiRagServer extends Server
{
    public array $tools = [
        WebSearchTool::class,
        // RAG search requires a separately reviewed Python-authorized tool.
    ];
}
