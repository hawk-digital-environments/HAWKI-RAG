<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use Illuminate\Support\Facades\Http;
use App\Mcp\Tools\McpToolHelpers;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

/**
 * Summary: MCP tool that queries RAWKI via the FastAPI bridge.
 * Keeps payload minimal; advanced knobs belong to the bridge.
 */
class RagQueryTool extends Tool
{
    public function description(): string
    {
        return 'Query RAWKI via the FastAPI bridge for RAG answers.';
    }

    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        return $schema
            ->string('query')->description('User question')->required()
            ->integer('top_k')->description('Number of chunks to retrieve')
            ->string('provider')->description('Embedding/LLM provider (e.g. ollama)')
            ->raw('filters', [
                'type' => 'object',
                'description' => 'Optional filters for retrieval',
            ])
            ->boolean('generate')->description('Whether to generate an answer')
            ->string('reranker')->description('none | cosine | external | jina')
            ->integer('rerank_top_n')->description('Number of candidates for reranking');
    }

    public function handle(array $arguments): ToolResult
    {
        $query = McpToolHelpers::trimString($arguments['query'] ?? null);
        if ($query === '') {
            return ToolResult::error('query is required');
        }

        $payload = [
            'query' => $query,
            'top_k' => McpToolHelpers::clampInt((int) ($arguments['top_k'] ?? 5), 1, 50),
            'provider' => $arguments['provider'] ?? null,
            'filters' => McpToolHelpers::toArray($arguments['filters'] ?? null),
            'generate' => $arguments['generate'] ?? true,
            'reranker' => $arguments['reranker'] ?? null,
            'rerank_top_n' => McpToolHelpers::clampInt((int) ($arguments['rerank_top_n'] ?? 20), 1, 50),
        ];

        $payload = array_filter($payload, static fn ($value) => $value !== null);

        $baseUrl = McpToolHelpers::rawkiBridgeBaseUrl();
        $response = Http::timeout(60)->post($baseUrl.'/query', $payload);

        if (! $response->successful()) {
            return ToolResult::error(sprintf('RAG query failed (%s): %s', $response->status(), $response->body()));
        }

        return ToolResult::json($response->json() ?? ['raw' => $response->body()]);
    }
}
