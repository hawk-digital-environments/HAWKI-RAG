<?php

declare(strict_types=1);

namespace App\Services\RagSearch;

use App\Services\Authorization\Values\AuthorizedDatasetScope;
use App\Services\Settings\SettingsService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class RagSearchPayloadFactory
{
    public function __construct(private SettingsService $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function make(string $query, int $topK, AuthorizedDatasetScope $scope): array
    {
        $modelRuntime = $this->settings->modelRuntime();

        return array_filter([
            'query' => $query,
            'authorized_scope' => $scope->toArray(),
            'top_k' => $topK,
            'provider' => $modelRuntime['provider'],
            'chat_model' => $modelRuntime['graph_model'],
            'vision_model' => $modelRuntime['vision_model'],
            'generate' => false,
            'reranker' => 'external',
            'rerank_top_n' => 20,
            'fast_mode' => false,
            'smart_lookup' => true,
            'structural_hops' => null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
