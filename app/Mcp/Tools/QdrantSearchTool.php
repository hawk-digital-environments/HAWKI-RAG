<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

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
        $collection = trim((string) ($arguments['collection'] ?? ''));
        if ($collection === '') {
            return ToolResult::error('collection is required');
        }

        $vector = $arguments['vector'] ?? null;
        if (! is_array($vector) || $vector === []) {
            return ToolResult::error('vector is required and must be a number array');
        }

        $limit = (int) ($arguments['limit'] ?? 5);
        $limit = max(1, min(50, $limit));

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

        $baseUrl = rtrim((string) env('QDRANT_HTTP_URL', 'http://qdrant:6333'), '/');
        $response = Http::timeout(20)
            ->post($baseUrl.'/collections/'.$collection.'/points/search', $payload);

        if (! $response->successful()) {
            return ToolResult::error(sprintf('Qdrant search failed (%s): %s', $response->status(), $response->body()));
        }

        return ToolResult::json($response->json() ?? ['raw' => $response->body()]);
    }
}
