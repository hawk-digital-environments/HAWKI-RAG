<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\Neo4jQueryTool;
use App\Mcp\Tools\QdrantSearchTool;
use App\Mcp\Tools\RagFolderIngestTool;
use App\Mcp\Tools\RagFolderListTool;
use App\Mcp\Tools\RagIngestTool;
use App\Mcp\Tools\RagQueryTool;
use App\Mcp\Tools\RawkiSearchTool;
use App\Mcp\Tools\WebSearchTool;
use Laravel\Mcp\Server;

class RawkiServer extends Server
{
    public array $tools = [
        WebSearchTool::class,
        RawkiSearchTool::class,
        RagQueryTool::class,
        RagIngestTool::class,
        RagFolderIngestTool::class,
        RagFolderListTool::class,
        QdrantSearchTool::class,
        Neo4jQueryTool::class,
    ];
}
