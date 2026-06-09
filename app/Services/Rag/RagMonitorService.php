<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Services\Document\DocumentRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class RagMonitorService
{
    public function __construct(
        private DocumentRepository $documents,
        private ConfigRepository $config,
        private Filesystem $files,
        private HttpFactory $http,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(): array
    {
        $bridge = $this->bridgeHealth();
        $runtime = $this->bridgeRuntime($bridge);

        return [
            'ok' => true,
            'bridge' => $bridge,
            'runtime' => $runtime,
            'config' => $this->graphConfig($runtime),
            'latest_ingest' => [
                'default' => $this->latestIngestStatus('default'),
                'neo4j' => $this->latestIngestStatus('neo4j'),
            ],
            'summary' => $this->firstJson($this->configList('config.ingest_summary_paths')),
            'graph_preview' => $this->firstJson($this->configList('config.graph_preview_paths')),
            'latest_document_graph' => $this->latestDocumentGraph(),
            'graph_failures' => $this->tailJsonLines((string) $this->config->get('config.graph_failures_path'), 5),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bridgeHealth(): array
    {
        $baseUrl = rtrim((string) $this->config->get('config.base_url', 'http://hawki_rag_bridge:8000'), '/');
        $endpoint = $baseUrl.'/health';

        try {
            $start = microtime(true);
            $response = $this->http->connectTimeout(2)->timeout(10)->get($endpoint);

            return [
                'ok' => $response->successful() && (bool) ($response->json('ok') ?? true),
                'status' => $response->status(),
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
                'endpoint' => $endpoint,
                'body' => $response->json(),
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'status' => 502,
                'latency_ms' => null,
                'endpoint' => $endpoint,
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function bridgeRuntime(array $health): ?array
    {
        $body = $health['body'] ?? null;

        return is_array($body) && is_array($body['runtime'] ?? null)
            ? $body['runtime']
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function graphConfig(?array $runtime): array
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

    private function latestIngestStatus(string $mode): ?array
    {
        $path = $mode === 'neo4j'
            ? (string) $this->config->get('config.ingest_status_path_neo4j')
            : (string) $this->config->get('config.ingest_status_path');
        $data = $this->readJson($path);

        if (! is_array($data)) {
            return null;
        }

        if (is_array($data['ingests'] ?? null) && $data['ingests'] !== []) {
            $latest = $data['ingests'][array_key_last($data['ingests'])];

            return is_array($latest) ? $latest : null;
        }

        return $data;
    }

    private function latestDocumentGraph(): ?array
    {
        $document = $this->documents->latestCompleted();
        if (! $document) {
            return null;
        }

        $metadata = is_array($document->metadata_json) ? $document->metadata_json : [];
        $summary = $metadata['bridge_response']['summary'] ?? null;
        $graphPreview = is_array($summary) && is_array($summary['graph_preview'] ?? null)
            ? $summary['graph_preview']
            : null;
        $graphConfig = is_array($summary) && is_array($summary['graph'] ?? null)
            ? $summary['graph']
            : [];

        return [
            'document_id' => $document->id,
            'external_id' => $document->external_id,
            'dataset_id' => $document->dataset_id,
            'collection' => $document->collection,
            'title' => $document->title,
            'source_url' => $document->source_url,
            'updated_at' => $document->updated_at?->toIso8601String(),
            'qdrant_points' => $metadata['bridge_response']['points'] ?? null,
            'graph_enabled' => (bool) ($graphConfig['enabled'] ?? $graphPreview),
            'graph_triplets' => $graphPreview['total_triplets'] ?? null,
            'docs_with_triplets' => $graphPreview['docs_with_triplets'] ?? null,
        ];
    }

    /**
     * @param list<string> $paths
     */
    private function firstJson(array $paths): ?array
    {
        foreach ($paths as $path) {
            $data = $this->readJson($path);
            if (is_array($data)) {
                return [
                    'path' => $path,
                    'updated_at' => date(DATE_ATOM, $this->files->lastModified($path)),
                    'data' => $data,
                ];
            }
        }

        return null;
    }

    private function readJson(string $path): ?array
    {
        if ($path === '' || ! $this->files->isFile($path)) {
            return null;
        }

        $decoded = json_decode($this->files->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tailJsonLines(string $path, int $limit): array
    {
        if ($path === '' || ! $this->files->isFile($path)) {
            return [];
        }

        $lines = preg_split('/\R/', trim($this->files->get($path))) ?: [];
        $items = [];
        foreach (array_slice($lines, -1 * max(1, $limit)) as $line) {
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            $items[] = is_array($decoded) ? $decoded : ['message' => $line];
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    private function configList(string $key): array
    {
        $value = $this->config->get($key, []);

        return is_array($value)
            ? array_values(array_filter(array_map('strval', $value)))
            : [];
    }
}
