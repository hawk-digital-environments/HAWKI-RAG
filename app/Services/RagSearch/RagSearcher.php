<?php

namespace App\Services\RagSearch;

use App\Services\RagSearch\Exception\RagSearcherFailedException;
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
            ]))->description('The list of all search results found for your query.')
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
            'fast_mode' => true,
            'smart_lookup' => false,
            // Fast mode explicitly disables graph traversal.
            'structural_hops' => true ? 0 : null,
        ];

        $baseUrl = config('config.base_url');

        try {
            $payload = array_filter($payload, static fn($value) => $value !== null);
            $response = Http::timeout(60)
                ->post($baseUrl . '/query', $payload);

            if(!$response->successful()){
                throw new RagSearcherFailedException($this->query, new \Exception('The request to the RAG backend was not successful!'));
            }

            return [
                'results' => [...$this->filterResponse($response->json())]
            ];
        } catch (\Throwable $exception){
            throw new RagSearcherFailedException($this->query, $exception);
        }
    }

    private function filterResponse(array $response): iterable
    {
        // @todo Arian said to me we should limit the amount of response data -> So I implemented this simple filter. Should probably adjusted to your needs.
        $hits = $response['hits'] ?? [];

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
                ]
            );

            if(empty($filteredHit)){
                continue;
            }

            yield $filteredHit;
        }
    }
}
