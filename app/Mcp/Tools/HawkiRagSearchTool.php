<?php
declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\RagSearch\RagSearcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Summary: MCP tool that queries the HAWKI RAG bridge with depth-aware search.
 * Depth controls retrieval cost and graph usage.
 */
#[Title('HAWKI RAG Query Search Tool')]
#[Name('query-search')]
#[Description('Search and retrieve specific information related to HAWK and internal knowledge base with a query.')]
class HawkiRagSearchTool extends Tool
{

    public function __construct(
        private readonly RagSearcher $searcher
    )
    {
    }

    /**
     * The input schema of the tool.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Retrieve relevant information from the knowledge base.
                Formulate a precise and context-rich search query including specific names, entities, relationships, dates, or domain terminology.
                Avoid vague or generic wording.
                The tool returns the most relevant structured results for downstream answer generation, reranking, or reasoning.')
                ->required(),
            'limit' => $schema->integer()
                ->description('Number of chunks to retrieve')
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return $this->searcher->getResponseSchema($schema);
    }

    public function handle(
        Request         $request,
        LoggerInterface $log
    ): ResponseFactory|Response
    {
        $validated = $request->validate([
            'query' => 'required|string',
            'limit' => 'integer|min:1|max:50',
            'top_k' => 'integer|min:1|max:50',
        ]);

        if (isset($validated['limit'], $validated['top_k']) && (int) $validated['limit'] !== (int) $validated['top_k']) {
            throw new RuntimeException('limit and top_k must match when both are provided.');
        }

        $query = $validated['query'];
        $limit = isset($validated['limit']) ? (int) $validated['limit'] : (isset($validated['top_k']) ? (int) $validated['top_k'] : 5);

        try {
            $response = $this->searcher
                ->withQuery($query)
                ->withTopK($limit)
                ->execute();

            return Response::structured([
                'instructions' => "When using this tool, always rely on the response from the RAG system. The response contains all relevant information and includes any possible links associated with the retrieved documents. Use the content and links comprehensively to answer queries, provide context, or perform reasoning. Prioritize completeness and relevance: incorporate all useful information from the RAG payload, and reference the links where applicable. Do not ignore any parts of the response that may aid in generating accurate and informative outputs.",
                'response' => $response
            ]);
        } catch (\Throwable $e) {
            $log->error(sprintf('Failed to retrieve hawki-rag search query: %s, with error: %s', $query, $e->getMessage()), ['exception' => $e]);
            return Response::error('We could not retrieve hawki-rag search query.');
        }
    }
}
