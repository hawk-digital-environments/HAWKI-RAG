<?php

declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use App\Services\Dataset\DatasetService;
use App\Services\Pipeline\Exceptions\PipelineEventHandlerException;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;

class IngestionBridgeClient
{
    public function __construct(
        private readonly DatasetService $datasets,
        private readonly HttpFactory $http,
        private readonly ConfigRepository $config,
    ) {}

    public function ingest(array $event, string $text, string $path): array
    {
        $response = $this->http->timeout((int) $this->config->get('communication.rabbitmq.pipeline_ingestion.bridge_timeout', 3600))
            ->acceptJson()
            ->asJson()
            ->post($this->bridgeUrl().'/ingest', $this->payload($event, $text, $path));

        if ($response->failed()) {
            throw PipelineEventHandlerException::bridgeReturnedHttpFailure($response->status(), Str::limit($response->body(), 1000));
        }

        $json = $response->json();

        return is_array($json) ? $json : ['ok' => true];
    }

    public function targets(string $datasetId): array
    {
        return $this->datasets->bridgeTargets($datasetId);
    }

    private function payload(array $event, string $text, string $path): array
    {
        $targets = $this->targets((string) ($event['dataset_id'] ?: 'default'));
        $payload = [
            'source_format' => 'markdown',
            'source_type' => $event['metadata']['source_event_type'] ?? $event['event_type'],
            'dataset_id' => $targets['dataset_id'],
            'qdrant_collection' => $targets['qdrant_collection'],
            'neo4j_namespace' => $targets['neo4j_namespace'],
            'file_path' => $path,
            'source_url' => $event['source_url'],
            'page_url' => $event['source_url'],
            'original_path' => $event['metadata']['original_path'] ?? $path,
            'converted_path' => $path,
            'input_checksum_sha256' => $event['content_hash'],
            'output_checksum_sha256' => $event['content_hash'],
            'trace_id' => $event['event_id'],
            'job_id' => $event['job_id'],
            'event_id' => $event['event_id'],
            'task_id' => $event['task_id'],
            'title' => pathinfo($path, PATHINFO_FILENAME),
        ];

        return [
            'docs' => [[
                'id' => (string) $event['job_id'],
                'text' => $text,
                'payload' => $payload,
            ]],
            'provider' => (string) $this->config->get('communication.rabbitmq.pipeline_ingestion.provider', 'ollama'),
            'embedding_model' => null,
            'collection' => $targets['qdrant_collection'],
            'neo4j_database' => null,
            'neo4j_namespace' => $targets['neo4j_namespace'],
            'distance' => (string) $this->config->get('config.qdrant_distance', 'Cosine'),
            'chunk_chars' => (int) $this->config->get('config.chunk_size', 1200),
            'chunk_overlap' => (int) $this->config->get('config.chunk_overlap_size', 250),
            'batch_size' => (int) $this->config->get('config.ingest_batch_size', 64),
            'graph' => filter_var($event['metadata']['graph'] ?? $this->config->get('communication.rabbitmq.pipeline_ingestion.graph', false), FILTER_VALIDATE_BOOLEAN),
            'graph_engine' => (string) $this->config->get('config.graph_engine', 'raganything'),
            'graph_model' => (string) $this->config->get('config.graph_default', ''),
            'graph_only' => false,
            'dry_run' => false,
            'dry_include_graph' => false,
        ];
    }

    private function bridgeUrl(): string
    {
        return rtrim((string) $this->config->get('config.hawki_rag_bridge_url', 'http://hawki_rag_bridge:8000'), '/');
    }
}
