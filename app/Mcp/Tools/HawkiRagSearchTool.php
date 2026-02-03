<?php
declare(strict_types=1);

namespace App\Mcp\Tools;

use Exception;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;
use App\Mcp\Tools\McpToolHelpers;

/**
 * Summary: MCP tool that queries the HAWKI RAG bridge with depth-aware search.
 * Depth controls retrieval cost and graph usage.
 */
#[Title('HAWKI RAG Query Search Tool')]
class HawkiRagSearchTool extends Tool
{
    /**
     * The Tool name
     */
    public function name(): string
    {
        return "hawki-rag-query-search";
    }

    /**
     * A description of the tool.
     */
    public function description(): string
    {
        return 'Search and retrieve specific information related to HAWK and internal knowledge base with a query.';
    }

    /**
     * The input schema of the tool.
     */
    public function schema(ToolInputSchema $schema): ToolInputSchema
    {
        $schema
            ->string('query')
            ->description('Query for information retrieval. Choose a specific and detailed query for best results.')
            ->required()
            ->integer('top_k')
            ->description('Number of chunks to retrieve')
            ->integer('depth')
            ->description('The depth of the search between 1 to 5. Higher depth number will result a more accurate search but will require longer search time. Use lower depth for simple queries and higher depth for more specific results.')
            ->raw('filters', [
                'type' => 'object',
                'description' => '(Optional) A list of keywords for search categories. Specific keywords can result faster and more specific retrieval.',
            ]);
        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        // Required query input
        $query = McpToolHelpers::trimString($arguments['query'] ?? null);
        if ($query === '') {
            return ToolResult::error('query is required');
        }

        try{
            $depth = McpToolHelpers::clampInt((int) ($arguments['depth'] ?? 2), 1, 5);
            $topK = McpToolHelpers::clampInt((int) ($arguments['top_k'] ?? 5), 1, 50);

            // Depth drives graph usage + retrieval breadth.
            // depth 1-2: fast mode (vector-only)
            // depth 3-5: enable graph traversal + smart lookup
            $fastMode = $depth <= 2;
            $smartLookup = $depth >= 3;
            $topK = $topK + ($depth >= 4 ? 5 : 0);

            $payload = [
                'query' => $query,
                'top_k' => $topK,
                'provider' => 'ollama',
                'filters' => McpToolHelpers::toArray($arguments['filters'] ?? null),
                'generate' => false,
                'reranker' => 'external',
                'rerank_top_n' => 20,
                'fast_mode' => $fastMode,
                'smart_lookup' => $smartLookup,
                // Fast mode explicitly disables graph traversal.
                'structural_hops' => $fastMode ? 0 : null,
            ];
            $payload = array_filter($payload, static fn ($value) => $value !== null);
            $baseUrl = McpToolHelpers::hawkiRagBridgeBaseUrl();
            $response = Http::timeout(60)->post($baseUrl.'/query', $payload);

            if (! $response->successful()) {
                return ToolResult::error(sprintf('RAG query failed (%s): %s', $response->status(), $response->body()));
            }

            return ToolResult::json($response->json());

        }
        catch (Exception $e){
            return ToolResult::error($e->getMessage());
        }
    }
}
