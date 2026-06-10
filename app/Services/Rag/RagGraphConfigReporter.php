<?php

declare(strict_types=1);

namespace App\Services\Rag;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class RagGraphConfigReporter
{
    public function __construct(private ConfigRepository $config)
    {
    }

    /**
     * @param array<string, mixed>|null $runtime
     * @return array<string, mixed>
     */
    public function report(?array $runtime): array
    {
        $limits = is_array($runtime['limits'] ?? null) ? $runtime['limits'] : [];

        return [
            'graph_engine' => $this->config->get('config.graph_engine', 'raganything'),
            'graph_provider' => $this->config->get('config.graph_provider', 'ollama'),
            'graph_model' => $this->config->get('config.graph_default'),
            'embedding_model' => $this->config->get('config.embedding_default'),
            'chunk_size' => (int) $this->config->get('config.chunk_size', 1200),
            'chunk_overlap' => (int) $this->config->get('config.chunk_overlap_size', 250),
            'graph_doc_max_chars' => (int) ($limits['graph_doc_max_chars'] ?? $this->config->get('config.graph_doc_max_chars', 0)),
            'graph_doc_max_chunks' => (int) ($limits['graph_doc_max_chunks'] ?? $this->config->get('config.graph_doc_max_chunks', 0)),
            'graph_reset_cache_per_doc' => (bool) $this->config->get('config.graph_reset_cache_per_doc', true),
        ];
    }
}
