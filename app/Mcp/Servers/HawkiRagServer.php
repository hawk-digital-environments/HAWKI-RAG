<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\WebSearchTool;
use Laravel\Mcp\Server;

class HawkiRagServer extends Server
{
    public array $tools = [
        WebSearchTool::class,
        // Epoch 15A: bridge-backed RAG search stays unregistered until it uses Python authority.
    ];
}
