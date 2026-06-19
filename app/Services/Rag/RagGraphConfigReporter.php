<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Services\Settings\SettingsService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class RagGraphConfigReporter
{
    public function __construct(
        private ConfigRepository $config,
        private SettingsService $settings,
    )
    {
    }

    /**
     * @param array<string, mixed>|null $runtime
     * @return array<string, mixed>
     */
    public function report(?array $runtime): array
    {
        $limits = is_array($runtime['limits'] ?? null) ? $runtime['limits'] : [];
        $modelRuntime = $this->settings->modelRuntime();

        return [
            'graph_engine' => $this->config->get('config.graph_engine', 'raganything'),
            'graph_provider' => $modelRuntime['provider'],
            'graph_model' => $modelRuntime['graph_model'],
            'embedding_model' => $modelRuntime['embedding_model'],
            'chunk_size' => (int) $this->config->get('config.chunk_size', 1200),
            'chunk_overlap' => (int) $this->config->get('config.chunk_overlap_size', 250),
            'graph_doc_max_chars' => (int) ($limits['graph_doc_max_chars'] ?? $this->config->get('config.graph_doc_max_chars', 0)),
            'graph_doc_max_chunks' => (int) ($limits['graph_doc_max_chunks'] ?? $this->config->get('config.graph_doc_max_chunks', 0)),
            'graph_reset_cache_per_doc' => (bool) $this->config->get('config.graph_reset_cache_per_doc', true),
        ];
    }
}
