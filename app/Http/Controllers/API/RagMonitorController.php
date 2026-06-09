<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\Document\DocumentRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;

class RagMonitorController extends Controller
{
    public function __construct(
        private readonly DocumentRepository $documents,
    ) {}

    public function show(): JsonResponse
    {
        $bridge = $this->bridgeHealth();
        $runtime = $this->bridgeRuntime($bridge);

        return response()->json([
            'ok' => true,
            'bridge' => $bridge,
            'runtime' => $runtime,
            'config' => $this->graphConfig($runtime),
            'latest_ingest' => [
                'default' => $this->latestIngestStatus('default'),
                'neo4j' => $this->latestIngestStatus('neo4j'),
            ],
            'summary' => $this->firstJson([
                public_path('ingest_summary.json'),
                storage_path('logs/ingest_summary.json'),
            ]),
            'graph_preview' => $this->firstJson([
                public_path('ingest_graph_preview.json'),
            ]),
            'latest_document_graph' => $this->latestDocumentGraph(),
            'graph_failures' => $this->tailJsonLines(public_path('ingest_graph_failures.jsonl'), 5),
        ]);
    }

    private function bridgeHealth(): array
    {
        $baseUrl = rtrim((string) config('config.base_url', 'http://hawki_rag_bridge:8000'), '/');

        try {
            $start = microtime(true);
            $response = Http::connectTimeout(2)->timeout(10)->get($baseUrl . '/health');

            return [
                'ok' => $response->successful() && (bool) ($response->json('ok') ?? true),
                'status' => $response->status(),
                'latency_ms' => (int) ((microtime(true) - $start) * 1000),
                'endpoint' => $baseUrl . '/health',
                'body' => $response->json(),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => 502,
                'latency_ms' => null,
                'endpoint' => $baseUrl . '/health',
                'error' => $e->getMessage(),
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

    private function graphConfig(?array $runtime): array
    {
        $limits = is_array($runtime['limits'] ?? null) ? $runtime['limits'] : [];

        return [
            'graph_engine' => env('GRAPH_ENGINE', 'raganything'),
            'graph_provider' => env('GRAPH_PROVIDER', 'ollama'),
            'graph_model' => config('config.graph_default'),
            'embedding_model' => config('config.embedding_default'),
            'chunk_size' => (int) env('CHUNK_SIZE', 1200),
            'chunk_overlap' => (int) env('CHUNK_OVERLAP_SIZE', 250),
            'graph_doc_max_chars' => (int) ($limits['graph_doc_max_chars'] ?? env('GRAPH_DOC_MAX_CHARS', 0)),
            'graph_doc_max_chunks' => (int) ($limits['graph_doc_max_chunks'] ?? env('GRAPH_DOC_MAX_CHUNKS', 0)),
            'graph_reset_cache_per_doc' => filter_var(env('GRAPH_RESET_CACHE_PER_DOC', true), FILTER_VALIDATE_BOOLEAN),
        ];
    }

    private function latestIngestStatus(string $mode): ?array
    {
        $path = $mode === 'neo4j'
            ? (string) config('config.ingest_status_path_neo4j', storage_path('logs/ingest_status_neo4j.json'))
            : (string) config('config.ingest_status_path', storage_path('logs/ingest_status.json'));
        $data = $this->readJson($path);

        if (!is_array($data)) {
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
            'updated_at' => optional($document->updated_at)->toIso8601String(),
            'qdrant_points' => $metadata['bridge_response']['points'] ?? null,
            'graph_enabled' => (bool) ($graphConfig['enabled'] ?? $graphPreview),
            'graph_triplets' => $graphPreview['total_triplets'] ?? null,
            'docs_with_triplets' => $graphPreview['docs_with_triplets'] ?? null,
        ];
    }

    private function firstJson(array $paths): ?array
    {
        foreach ($paths as $path) {
            $data = $this->readJson((string) $path);
            if (is_array($data)) {
                return [
                    'path' => (string) $path,
                    'updated_at' => date(DATE_ATOM, (int) filemtime((string) $path)),
                    'data' => $data,
                ];
            }
        }

        return null;
    }

    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        $decoded = $raw ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    private function tailJsonLines(string $path, int $limit): array
    {
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $items = [];
        foreach (array_slice($lines, -1 * max(1, $limit)) as $line) {
            $decoded = json_decode($line, true);
            $items[] = is_array($decoded) ? $decoded : ['message' => $line];
        }

        return $items;
    }
}
