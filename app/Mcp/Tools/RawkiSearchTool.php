<?php
declare(strict_types=1);

namespace App\Mcp\Tools;

use Exception;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\Title;
use Laravel\Mcp\Server\Tools\ToolInputSchema;
use Laravel\Mcp\Server\Tools\ToolResult;

#[Title('Rawki Query Search Tool')]
class RawkiSearchTool extends Tool
{
    /**
     * The Tool name
     */
    public function name(): string
    {
        return "rawki-query-search";
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
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return ToolResult::error('query is required');
        }

        try{
            $payload = [
                'query' => $query,
                'top_k' => (int) ($arguments['top_k'] ?? 5),
                'provider' => 'ollama',
                'filters' => is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [],
                'generate' => false,
                'reranker' => 'external',
                'rerank_top_n' => 20,
            ];
            $baseUrl = config('rawki.rawki_bridge_url');
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
