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
        return "query-search";
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
            ->description('Retrieve relevant information from the knowledge base.
                Formulate a precise and context-rich search query including specific names, entities, relationships, dates, or domain terminology.
                Avoid vague or generic wording.
                The tool returns the most relevant structured results for downstream answer generation, reranking, or reasoning.') ->required()
            ->integer('top_k')
            ->description('Number of chunks to retrieve');

        return $schema;
    }

    public function handle(array $arguments): ToolResult
    {
        // \Log::debug('checkpoint 0');
        // \Log::debug($arguments);
        // Required query input
        $query = McpToolHelpers::trimString($arguments['query'] ?? null);
        if ($query === '') {
            return ToolResult::error('query is required');
        }

        try{
            // \Log::debug('checkpoint 1');
            $topK = McpToolHelpers::clampInt((int) ($arguments['top_k'] ?? 5), 1, 50);

            // Depth drives graph usage + retrieval breadth.
            // depth 1-2: fast mode (vector-only)
            // depth 3-5: enable graph traversal + smart lookup

            // \Log::debug('checkpoint 2');
            $payload = [
                'query' => $query,
                'top_k' => 15,
                'provider' => 'ollama',
                'generate' => false,
                'reranker' => 'external',
                'rerank_top_n' => 20,
                'fast_mode' => true,
                'smart_lookup' => false,
                // Fast mode explicitly disables graph traversal.
                'structural_hops' => true ? 0 : null,
            ];

            $baseUrl = config('hawki_rag.base_url');
            // \Log::debug('checkpoint 3');
            // \Log::debug($payload);
            $payload = array_filter($payload, static fn ($value) => $value !== null);
            $response = Http::timeout(60)
                ->post($baseUrl . '/query', $payload);

            if (!$response->successful()) {

                return ToolResult::error(sprintf('RAG query failed (%s): %s',
                    $response->status(), $response->body()));
            }

            $resData = [
                'success' => true,
                'instructions' => "When using this tool, always rely on the response from the RAG system. The response contains all relevant information and includes any possible links associated with the retrieved documents. Use the content and links comprehensively to answer queries, provide context, or perform reasoning. Prioritize completeness and relevance: incorporate all useful information from the RAG payload, and reference the links where applicable. Do not ignore any parts of the response that may aid in generating accurate and informative outputs.",
                'response' => $response->json()
            ];
            \Log::debug($resData);
            return ToolResult::json($resData);

        }
        catch (Exception $e){
            return ToolResult::error($e->getMessage());
        }
    }
}
