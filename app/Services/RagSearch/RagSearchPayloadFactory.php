<?php

declare(strict_types=1);

namespace App\Services\RagSearch;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class RagSearchPayloadFactory
{
    /**
     * @return array<string, mixed>
     */
    public function make(string $query, int $topK): array
    {
        return array_filter([
            'query' => $query,
            'top_k' => $topK,
            'provider' => 'ollama',
            'generate' => false,
            'reranker' => 'external',
            'rerank_top_n' => 20,
            'fast_mode' => false,
            'smart_lookup' => true,
            'structural_hops' => null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
