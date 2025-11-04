<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\RagTool;
use Laravel\Mcp\Server;

class RawkiServer extends Server
{
    /**
     * The MCP server's name.
     */
    protected string $name = 'RawkiServer';

    /**
     * The MCP server's version.
     */
    protected string $version = '0.0.1';

    /**
     * The MCP server's instructions for the LLM.
     */
    protected string $instructions = <<<'MARKDOWN'
        RAWKI Server fetches and returns specific information about HAWK University.
    MARKDOWN;

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        RagTool::class,
    ];
}
