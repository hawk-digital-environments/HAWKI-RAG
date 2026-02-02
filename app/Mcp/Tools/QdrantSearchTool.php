<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\Http;
use App\Mcp\Tools\McpToolHelpers;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Summary: MCP tool that runs vector search queries against Qdrant.
 * Expects a precomputed embedding vector from the caller.
 */
class QdrantSearchTool extends Tool
{
    public function description(): string
    {
        return 'Search Qdrant using a provided vector and optional filter.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('collection')->description('Qdrant collection name')->required()
            ->raw('vector', [
                'type' => 'array',
                'items' => ['type' => 'number'],
                'description' => 'Embedding vector to search with',
            ])->required()
            ->integer('limit')->description('Number of results to return')
            ->raw('filter', [
                'type' => 'object',
                'description' => 'Qdrant filter object',
            ])
            ->boolean('with_payload')->description('Include payload in results');
    }

    public function handle(array $arguments): ToolResult
    {
        $collection = McpToolHelpers::trimString($arguments['collection'] ?? null);
        if ($collection === '') {
            return ToolResult::error('collection is required');
        }

        $vector = $arguments['vector'] ?? null;
        if (! is_array($vector) || $vector === []) {
            return ToolResult::error('vector is required and must be a number array');
        }

        $limit = McpToolHelpers::clampInt((int) ($arguments['limit'] ?? 5), 1, 50);

        $filter = $arguments['filter'] ?? null;
        if (! is_array($filter)) {
            $filter = null;
        }

        $withPayload = $arguments['with_payload'] ?? true;
        $withPayload = filter_var($withPayload, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($withPayload === null) {
            $withPayload = true;
        }

        $payload = [
            'vector' => $vector,
            'limit' => $limit,
            'with_payload' => $withPayload,
        ];
        if ($filter !== null) {
            $payload['filter'] = $filter;
        }

        $baseUrl = McpToolHelpers::qdrantBaseUrl();
        $response = Http::timeout(20)
            ->post($baseUrl.'/collections/'.$collection.'/points/search', $payload);

        if (! $response->successful()) {
            return ToolResult::error(sprintf('Qdrant search failed (%s): %s', $response->status(), $response->body()));
        }

        return ToolResult::json($response->json() ?? ['raw' => $response->body()]);
    }
}
