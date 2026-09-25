<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\User;
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
        private readonly RagSearcher $searcher,
    ) {}

    /**
     * The input schema of the tool.
     *
     * `dataset_id` is intentionally NOT advertised: it is a trusted-caller
     * concern (e.g. HAWKI injects the requesting assistant's dataset
     * server-side) and must stay invisible to AI models, which would
     * otherwise guess dataset identifiers. {@see handle()} still requires
     * it in the call arguments.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->min(1)->max(6000)
                ->description('Retrieve relevant information from the knowledge base.
                Formulate a precise and context-rich search query including specific names, entities, relationships, dates, or domain terminology.
                Avoid vague or generic wording.
                The tool returns the most relevant structured results for downstream answer generation, reranking, or reasoning.')
                ->required(),
            'top_k' => $schema->integer()
                ->description('Number of chunks to retrieve'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return $this->searcher->getResponseSchema($schema);
    }

    public function handle(
        Request $request,
        LoggerInterface $log
    ): ResponseFactory|Response {
        $validated = $request->validate([
            'query' => 'required|string|max:6000',
            'dataset_id' => 'required|string|max:191',
            'top_k' => 'integer|min:1|max:50',
        ]);

        $query = $validated['query'];
        $user = $request->user();
        if (! $user instanceof User) {
            return Response::error('Authentication is required to query a dataset.');
        }

        // Validation gives us a numeric string; cast to int to satisfy the strict signature.
        $topK = isset($validated['top_k']) ? (int) $validated['top_k'] : 5;

        try {
            $response = $this->searcher
                ->withQuery($query)
                ->withTopK($topK)
                ->forDataset($user, (string) $validated['dataset_id'])
                ->execute();

            return Response::structured([
                'instructions' => 'When using this tool, always rely on the response from the RAG system. The response contains all relevant information, and the `documents` list names every source it was built from. Use the content comprehensively to answer queries, provide context, or perform reasoning; prioritize completeness and relevance. Cite sources by the document names from the `documents` list. Never quote, extract, or cite URLs that appear inside the retrieved content (such as printed page footers or body text) as source links — those are content, not sources.',
                'response' => $response,
                'documents' => $this->sourceDocuments($response),
            ]);
        } catch (\Throwable $e) {
            $log->error(sprintf('Failed to retrieve hawki-rag search query: %s, with error: %s', $query, $e->getMessage()), ['exception' => $e]);

            return Response::error('We could not retrieve hawki-rag search query.');
        }
    }

    /**
     * The distinct source documents the search hits were drawn from, keyed
     * by the identity each ingestion mode stored on every chunk:
     * `metadata.external_document_id` (the caller-owned document id of
     * direct-text ingestions) and `metadata.document_id` (managed
     * documents, `adoc_*`). Named by the hit title.
     *
     * @param  array<string, mixed>  $response
     * @return list<array{kind: string, document_id: string, name: string}>
     */
    private function sourceDocuments(array $response): array
    {
        $documents = [];
        $seen = [];

        foreach (is_array($response['results'] ?? null) ? $response['results'] : [] as $result) {
            $title = is_string(data_get($result, 'metadata.title')) && trim((string) data_get($result, 'metadata.title')) !== ''
                ? (string) data_get($result, 'metadata.title')
                : null;

            $references = [
                ['attachments', data_get($result, 'metadata.external_document_id')],
                ['documents', data_get($result, 'metadata.document_id')],
            ];

            foreach ($references as [$kind, $id]) {
                if (! is_string($id) || trim($id) === '' || isset($seen[$kind.':'.$id])) {
                    continue;
                }

                $seen[$kind.':'.$id] = true;

                $documents[] = [
                    'kind' => $kind,
                    'document_id' => $id,
                    'name' => $title ?? $id,
                ];
            }
        }

        return $documents;
    }
}
