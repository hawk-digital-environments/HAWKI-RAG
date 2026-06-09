<?php

namespace App\Services\RagSearch;

use App\Services\RagSearch\Exceptions\RagSearcherFailedException;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;

class RagSearcher
{
    private string|null $query = null;
    private int $topK = 15;

    public function __construct(
        #[Config('config.base_url')]
        private readonly string $baseUrl
    )
    {
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function withQuery(string|null $query): static
    {
        $clone = clone $this;
        $clone->query = $query;
        return $clone;
    }

    public function getQuery(): string|null {
        return $this->query;
    }

    public function withTopK(int $topK): static
    {
        if($topK < 1){
            throw new \InvalidArgumentException("TopK must be a positive integer");
        }
        $clone = clone $this;
        $clone->topK = $topK;
        return $clone;
    }

    public function getTopK(): int {
        return $this->topK;
    }

    public function getResponseSchema(JsonSchema $schema): array
    {
        // Single source of truth for relation-shaped fields used by both chunk hits and KG triples.
        $relationFields = [
            'subject' => $schema->string()->description('Subject node of the relation'),
            'relation' => $schema->string()->description('Predicate/edge of the relation'),
            'object' => $schema->string()->description('Object node of the relation'),
        ];

        return [
            'results' => $schema->array()->items($schema->object([
                'metadata' => $schema->object([
                    'language' => $schema->string()->description('The two char language code of the content in this result'),
                    'title' => $schema->string()->description('The title of the search result. Normally this is the page or document title'),
                    'url' => $schema->string()->description('The url of the search result'),
                    'timestamp' => $schema->string()->description('The timestamp of the search result'),
                    'tags' => $schema->string()->description('A comma separated list of tags to quantify the search result'),
                    'collection' => $schema->string()->description('The name of the knowledge pool where the content was extracted from')
                ])->description('Additional metadata about the result'),
                'content' => $schema->string()->description('The content of the search result. This is a string in markdown syntax'),
                'component_type' => $schema->string()->description('Chunk type. "relation" denotes a graph triple; otherwise a content chunk.'),
                // Relation fields are present when component_type === "relation".
                ...$relationFields,
            ]))->description('The list of all search results found for your query.'),
            // KG triples mirror the relation fields used in hits.
            'kg' => $schema->array()->items($schema->object($relationFields))->description('Knowledge-graph relations returned by the backend'),
            'rewrite_terms' => $schema->array()->items(
                $schema->string()->description('Entity term produced by backend query rewrite')
            )->description('Entity terms extracted by the backend during query rewrite'),
        ];
    }

    public function execute(): array
    {
        if(empty($this->query)){
            throw new RagSearcherFailedException("No query provided");
        }

        $payload = [
            'query' => $this->query,
            'top_k' => $this->topK,
            'provider' => 'ollama',
            'generate' => false,
            'reranker' => 'external',
            'rerank_top_n' => 20,
            'fast_mode' => false,
            'smart_lookup' => true,
            // Fast mode explicitly disables graph traversal.
            // Let the RAG service decide graph depth (via env). Set to null so it isn't forced off.
            'structural_hops' => null,
        ];

        $baseUrl = config('config.base_url');

        try {
            $payload = array_filter($payload, static fn($value) => $value !== null);
            $response = Http::timeout(60)
                ->post($baseUrl . '/query', $payload);

            if(!$response->successful()){
                throw new RagSearcherFailedException($this->query, new \Exception('The request to the RAG backend was not successful!'));
            }

            return $this->filterResponse($response->json());
        } catch (\Throwable $exception){
            throw new RagSearcherFailedException($this->query, $exception);
        }
    }

    private function filterResponse(array $response): array
    {
        // @todo Arian said to me we should limit the amount of response data -> So I implemented this simple filter. Should probably adjusted to your needs.
        $hits = $response['hits'] ?? [];
        $kgRelations = $response['kg'] ?? [];
        $rewriteTerms = $response['retrieval']['rewrite']['entity_terms'] ?? [];

        $results = [];
        foreach ($hits as $hit) {
            $filteredHit = array_filter(
                [
                    'metadata' => array_filter(
                        [
                            'language' => $hit['payload']['lang'] ?? null,
                            'title' => $hit['payload']['title'] ?? null,
                            'url' => $hit['payload']['page_url'] ?? null,
                            'timestamp' => $hit['payload']['updated_at'] ?? null,
                            'tags' => !empty($hit['payload']['tags']) ? implode(',', $hit['payload']['tags']) : null,
                            'collection' => $hit['collection'] ?? null,
                        ]
                    ),
                    'content' => $hit['payload']['content'] ?? null,
                    'component_type' => $hit['payload']['component_type'] ?? null,
                    'subject' => $hit['payload']['subject'] ?? null,
                    'relation' => $hit['payload']['relation'] ?? null,
                    'object' => $hit['payload']['object'] ?? null,
                ]
            );

            if(empty($filteredHit)){
                continue;
            }

            $results[] = $filteredHit;
        }

        $kg = [];
        foreach ($kgRelations as $fact) {
            $subject = $fact['subject'] ?? null;
            $relation = $fact['relation'] ?? null;
            $object = $fact['object'] ?? null;
            if($subject && $relation && $object){
                $kg[] = [
                    'subject' => $subject,
                    'relation' => $relation,
                    'object' => $object,
                ];
            }
        }

        $entityTerms = array_values(array_unique(array_filter(
            is_array($rewriteTerms) ? $rewriteTerms : [],
            static fn($term) => is_string($term) && trim($term) !== ''
        )));

        return [
            'results' => $results,
            'kg' => $kg,
            'rewrite_terms' => $entityTerms,
        ];
    }
}
