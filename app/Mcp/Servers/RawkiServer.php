<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\Neo4jQueryTool;
use App\Mcp\Tools\QdrantSearchTool;
use App\Mcp\Tools\RagFolderIngestTool;
use App\Mcp\Tools\RagFolderListTool;
use App\Mcp\Tools\RagIngestTool;
use App\Mcp\Tools\RagQueryTool;
use App\Mcp\Tools\SearchTool;
use Laravel\Mcp\Server;

class RawkiServer extends Server
{
    public array $tools = [
        SearchTool::class,
        Neo4jQueryTool::class,
        QdrantSearchTool::class,
        RagQueryTool::class,
        RagIngestTool::class,
        RagFolderIngestTool::class,
        RagFolderListTool::class,
    ];
}
