<?php
declare(strict_types=1);

namespace App\Services\Rag;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class RagSearchResponseFactory
{
    /**
     * @return array{query: string, count: int, results: list<array<string, mixed>>}
     */
    public function fromBridgePayload(string $query, mixed $payload): array
    {
        $hits = is_array($payload) && is_array($payload['hits'] ?? null) ? $payload['hits'] : [];
        $results = [];

        foreach ($hits as $hit) {
            if (! is_array($hit)) {
                continue;
            }

            $payload = is_array($hit['payload'] ?? null) ? $hit['payload'] : [];
            $results[] = [
                'id' => $hit['id'] ?? null,
                'document_id' => $this->documentId($payload),
                'score' => $hit['score'] ?? null,
                'content' => $this->content($payload),
                'metadata' => $this->metadata($payload),
            ];
        }

        return [
            'query' => $query,
            'count' => count($results),
            'results' => $results,
        ];
    }

    /**
     * @return array{error: string, message: string, details: mixed}
     */
    public function error(mixed $payload): array
    {
        return [
            'error' => 'search_failed',
            'message' => is_array($payload) && is_string($payload['message'] ?? null)
                ? $payload['message']
                : 'Search backend failed.',
            'details' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function documentId(array $payload): ?string
    {
        foreach (['document_id', 'documentId', 'doc_id'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function content(array $payload): ?string
    {
        foreach (['content', 'text'] as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function metadata(array $payload): array
    {
        unset($payload['content'], $payload['text']);

        return $payload;
    }
}
