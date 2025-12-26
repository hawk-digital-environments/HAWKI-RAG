<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

class Neo4jQueryTool extends Tool
{
    public function description(): string
    {
        return 'Execute a Cypher query against Neo4j (read-only unless you choose to write).';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('cypher')->description('Cypher query')->required()
            ->raw('params', [
                'type' => 'object',
                'description' => 'Cypher parameters',
            ]);
    }

    public function handle(array $arguments): ToolResult
    {
        $cypher = trim((string) ($arguments['cypher'] ?? ''));
        if ($cypher === '') {
            return ToolResult::error('cypher is required');
        }

        $params = $arguments['params'] ?? [];
        if (! is_array($params)) {
            $params = [];
        }

        $baseUrl = rtrim((string) env('NEO4J_HTTP_URL', 'http://neo4j:7474'), '/');
        $user = (string) env('NEO4J_USER', 'neo4j');
        $password = (string) env('NEO4J_PASSWORD', 'ixdlabPass123');

        $response = Http::timeout(20)
            ->withBasicAuth($user, $password)
            ->post($baseUrl.'/db/neo4j/tx/commit', [
                'statements' => [
                    [
                        'statement' => $cypher,
                        'parameters' => $params,
                    ],
                ],
            ]);

        if (! $response->successful()) {
            return ToolResult::error(sprintf('Neo4j query failed (%s): %s', $response->status(), $response->body()));
        }

        return ToolResult::json($response->json() ?? ['raw' => $response->body()]);
    }
}
