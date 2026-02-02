<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\RawkiSearchTool;
use App\Mcp\Tools\WebSearchTool;
use Laravel\Mcp\Server;

class RawkiServer extends Server
{
    public array $tools = [
        WebSearchTool::class,
        RawkiSearchTool::class,
    ];
}
