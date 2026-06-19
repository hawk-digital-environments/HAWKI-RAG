<?php

declare(strict_types=1);

namespace App\Services\RagSearch;

use App\Services\Settings\SettingsService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class RagSearchPayloadFactory
{
    public function __construct(private SettingsService $settings)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function make(string $query, int $topK): array
    {
        $modelRuntime = $this->settings->modelRuntime();

        return array_filter([
            'query' => $query,
            'top_k' => $topK,
            'provider' => $modelRuntime['provider'],
            'generate' => false,
            'reranker' => 'external',
            'rerank_top_n' => 20,
            'fast_mode' => false,
            'smart_lookup' => true,
            'structural_hops' => null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
