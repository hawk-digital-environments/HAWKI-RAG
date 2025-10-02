<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SemanticQdrantSearch
{
    protected string $ollamaUrl;        // e.g., http://ollama:11434/api
    protected string $qdrantBaseUrl;    // e.g., http://qdrant:6333
    protected string $qdrantCollection; // e.g., embeddings
    protected int $collectionVectorSize;

    public function __construct()
    {
        $this->ollamaUrl        = rtrim(env('OLLAMA_API_URL', 'http://127.0.0.1:11434/api'), '/');
        $this->qdrantBaseUrl    = rtrim(env('QDRANT_URL', 'http://127.0.0.1:6333'), '/');
        $this->qdrantCollection = env('QDRANT_COLLECTION', 'embeddings');
        $this->collectionVectorSize = $this->getQdrantVectorSize();
    }

    /**
     * Perform a semantic search against Qdrant using an Ollama embedding.
     */
    public function search(string $query, int $topK = 5, array $filters = []): array
    {
        Log::info("SemanticQdrantSearch query: {$query}");

        // 1) Get embedding from Ollama
        $embeddingResponse = Http::timeout(15)
            ->post("{$this->ollamaUrl}/embeddings", [
                'model'  => 'bge-m3',
                'prompt' => $query,
            ])
            ->throw();

        if (!isset($embeddingResponse['embedding']) || !is_array($embeddingResponse['embedding'])) {
            Log::error('Unexpected Ollama response format', ['body' => $embeddingResponse->json()]);
            throw new \RuntimeException('Unexpected embedding response from Ollama');
        }

        $vector = $embeddingResponse['embedding'];
        $originalLen = count($vector);

        // 2) Match vector length to collection vector size
        if ($originalLen < $this->collectionVectorSize) {
            $vector = array_pad($vector, $this->collectionVectorSize, 0.0);
            Log::info("Padded vector from {$originalLen} to {$this->collectionVectorSize}");
        } elseif ($originalLen > $this->collectionVectorSize) {
            $vector = array_slice($vector, 0, $this->collectionVectorSize);
            Log::info("Truncated vector from {$originalLen} to {$this->collectionVectorSize}");
        }

        // 3) Build Qdrant filter if provided
        $filterConditions = [];
        foreach ($filters as $key => $value) {
            $filterConditions[] = [
                'key'   => $key,
                'match' => ['value' => $value],
            ];
        }

        $qdrantBody = [
            'vector'        => $vector,
            'limit'         => $topK,
            'with_payload'  => true,
            // 'score_threshold' => 0.2, // optional
        ];
        if ($filterConditions) {
            $qdrantBody['filter'] = ['must' => $filterConditions];
        }

        // 4) Query Qdrant
        $qdrantUrl = "{$this->qdrantBaseUrl}/collections/{$this->qdrantCollection}/points/search";
        $qdrantResponse = Http::timeout(15)
            ->post($qdrantUrl, $qdrantBody)
            ->throw();

        if (!isset($qdrantResponse['result']) || !is_array($qdrantResponse['result'])) {
            Log::error('Unexpected Qdrant search response', ['body' => $qdrantResponse->json()]);
            throw new \RuntimeException('Unexpected search response from Qdrant');
        }

        return $qdrantResponse['result'];
    }

    /**
     * Detect the vector size from Qdrant collection config.
     */
    private function getQdrantVectorSize(): int
    {
        try {
            $url = "{$this->qdrantBaseUrl}/collections/{$this->qdrantCollection}";
            $response = Http::timeout(10)->get($url)->throw();

            // Single-vector schema
            if (isset($response['result']['config']['params']['vectors']['size'])) {
                return (int) $response['result']['config']['params']['vectors']['size'];
            }

            // Named multi-vector schema
            if (isset($response['result']['config']['params']['vectors']['params'])) {
                $params = $response['result']['config']['params']['vectors']['params'];
                $first  = reset($params);
                if (isset($first['size'])) {
                    return (int) $first['size'];
                }
            }
        } catch (\Throwable $e) {
            Log::error("Failed to fetch Qdrant vector size", ['exception' => $e]);
        }

        // Fallback: bge-m3 embeddings are 1024 dims
        return 1024;
    }
}
