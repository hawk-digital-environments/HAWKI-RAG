<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\HawkiRagSearchTool;
use App\Mcp\Tools\WebSearchTool;
use Laravel\Mcp\Server;

class HawkiRagServer extends Server
{
    public array $tools = [
        WebSearchTool::class,
        HawkiRagSearchTool::class,
        // RagQueryTool::class,
    ];
}
