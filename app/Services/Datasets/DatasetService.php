<?php

namespace App\Services\Datasets;

use App\Models\Dataset;
use App\Models\Document;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
class DatasetService
{
    public function __construct(
        private readonly DatasetRepository $datasets,
        private readonly ConfigRepository $config,
        private readonly HttpFactory $http,
        private readonly ClockInterface $clock = new Clock,
    ) {}

    public function list(int $limit = 50): array
    {
        $limit = max(1, min(250, $limit));

        return $this->datasets->recentWithTasks($limit)
            ->map(fn (Dataset $dataset): array => $this->payload($dataset, includeDetails: false))
            ->all();
    }

    public function show(string $datasetId): ?array
    {
        $dataset = $this->datasets->findByDatasetId($datasetId);

        return $dataset ? $this->payload($dataset, includeDetails: true) : null;
    }

    public function create(array $input): Dataset
    {
        $datasetId = $this->datasetId($input['dataset_id'] ?? $input['datasetId'] ?? null);
        $safe = $this->safeName($datasetId);

        return $this->datasets->create([
            'dataset_id' => $datasetId,
            'name' => $this->stringValue($input['name'] ?? null) ?? Str::headline(str_replace(['_', '-'], ' ', $datasetId)),
            'description' => $this->stringValue($input['description'] ?? null),
            'status' => $this->stringValue($input['status'] ?? null) ?? Dataset::STATUS_ACTIVE,
            'qdrant_collection' => $this->stringValue($input['qdrant_collection'] ?? $input['qdrantCollection'] ?? null)
                ?? $this->qdrantCollectionFor($safe),
            'neo4j_namespace' => $this->stringValue($input['neo4j_namespace'] ?? $input['neo4jNamespace'] ?? null)
                ?? $this->neo4jNamespaceFor($safe),
            'created_at' => $this->clock->now(),
        ]);
    }

    public function ensure(string|array|null $dataset = null, array $input = []): Dataset
    {
        if (is_array($dataset)) {
            $input = $dataset;
            $dataset = null;
        }

        $datasetId = $this->datasetId($dataset ?? $input['dataset_id'] ?? $input['datasetId'] ?? null);
        $safe = $this->safeName($datasetId);

        return $this->datasets->firstOrCreate($datasetId, [
            'name' => $this->stringValue($input['name'] ?? null) ?? Str::headline(str_replace(['_', '-'], ' ', $datasetId)),
            'description' => $this->stringValue($input['description'] ?? null),
            'status' => Dataset::STATUS_ACTIVE,
            'qdrant_collection' => $this->stringValue($input['qdrant_collection'] ?? $input['qdrantCollection'] ?? null)
                ?? $this->qdrantCollectionFor($safe),
            'neo4j_namespace' => $this->stringValue($input['neo4j_namespace'] ?? $input['neo4jNamespace'] ?? null)
                ?? $this->neo4jNamespaceFor($safe),
            'created_at' => $this->clock->now(),
        ]);
    }

    public function bridgeTargets(string $datasetId): array
    {
        $dataset = $this->ensure($datasetId);

        return [
            'dataset_id' => $dataset->dataset_id,
            'qdrant_collection' => $dataset->qdrant_collection,
            'neo4j_namespace' => $dataset->neo4j_namespace,
        ];
    }

    private function payload(Dataset $dataset, bool $includeDetails): array
    {
        $stats = $this->stats($dataset);
        $payload = [
            'id' => $dataset->id,
            'datasetId' => $dataset->dataset_id,
            'name' => $dataset->name,
            'description' => $dataset->description,
            'status' => $dataset->status,
            'qdrantCollection' => $dataset->qdrant_collection,
            'neo4jNamespace' => $dataset->neo4j_namespace,
            'createdAt' => $dataset->created_at?->format(DATE_ATOM),
            'documentCount' => $stats['documents'],
            'taskCount' => $stats['tasks'],
            'lastIngestion' => $stats['lastIngestion'],
            'graphStats' => $stats['graph'],
        ];

        if ($includeDetails) {
            $payload['tasks'] = $this->tasks($dataset);
            $payload['documents'] = $this->documents($dataset);
            $payload['ingestionHistory'] = $this->ingestionHistory($dataset);
        }

        return $payload;
    }

    private function stats(Dataset $dataset): array
    {
        return [
            'documents' => $this->datasets->documentCount($dataset),
            'tasks' => $this->datasets->taskCount($dataset),
            'lastIngestion' => $this->lastIngestion($dataset),
            'graph' => [
                'qdrant' => $this->qdrantStats($dataset),
                'neo4j' => $this->neo4jStats($dataset),
            ],
        ];
    }

    private function tasks(Dataset $dataset): array
    {
        return $this->datasets->recentTasks($dataset)
            ->map(fn (PipelineTask $task): array => [
                'taskId' => $task->task_id,
                'datasetId' => $task->dataset_id,
                'status' => $task->status,
                'counters' => $task->counters ?? [],
                'startedAt' => $task->started_at?->format(DATE_ATOM),
                'finishedAt' => $task->finished_at?->format(DATE_ATOM),
            ])
            ->all();
    }

    private function documents(Dataset $dataset): array
    {
        return $this->datasets->recentDocuments($dataset)
            ->map(fn (Document $document): array => [
                'id' => $document->id,
                'datasetId' => $document->dataset_id,
                'collection' => $document->collection,
                'sourceType' => $document->source_type,
                'sourceUrl' => $document->source_url,
                'originalFilename' => $document->original_filename,
                'storagePath' => $document->storage_path,
                'checksumSha256' => $document->checksum_sha256,
                'title' => $document->title,
                'status' => $document->status,
                'createdAt' => $document->created_at?->format(DATE_ATOM),
                'updatedAt' => $document->updated_at?->format(DATE_ATOM),
            ])
            ->all();
    }

    private function ingestionHistory(Dataset $dataset): array
    {
        return $this->datasets->recentIngestionJobs($dataset)
            ->map(fn (PipelineJob $job): array => [
                'jobId' => $job->job_id,
                'taskId' => $job->task_id,
                'status' => $job->status,
                'sourceUrl' => $job->source_url,
                'localPath' => $job->local_path,
                'errorMessage' => $job->error_message,
                'startedAt' => $job->started_at?->format(DATE_ATOM),
                'finishedAt' => $job->finished_at?->format(DATE_ATOM),
            ])
            ->all();
    }

    private function lastIngestion(Dataset $dataset): ?array
    {
        $job = $this->datasets->lastTerminalIngestionJob($dataset);

        if (!$job) {
            return null;
        }

        return [
            'jobId' => $job->job_id,
            'taskId' => $job->task_id,
            'status' => $job->status,
            'finishedAt' => $job->finished_at?->format(DATE_ATOM),
        ];
    }

    private function qdrantStats(Dataset $dataset): array
    {
        $baseUrl = rtrim((string) $this->config->get('config.qdrant_http_url', 'http://qdrant:6333'), '/');

        try {
            $response = $this->http->timeout(3)->post($baseUrl . '/collections/' . rawurlencode($dataset->qdrant_collection) . '/points/count', [
                'exact' => true,
            ]);

            if (!$response->successful()) {
                return [
                    'ok' => false,
                    'collection' => $dataset->qdrant_collection,
                    'points' => null,
                    'error' => 'Qdrant HTTP ' . $response->status(),
                ];
            }

            return [
                'ok' => true,
                'collection' => $dataset->qdrant_collection,
                'points' => (int) ($response->json('result.count') ?? 0),
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'collection' => $dataset->qdrant_collection,
                'points' => null,
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function neo4jStats(Dataset $dataset): array
    {
        $baseUrl = rtrim((string) $this->config->get('config.neo4j_http_url', 'http://hawki_rag_neo4j:7474'), '/');
        $database = trim((string) $this->config->get('config.neo4j_database', 'neo4j')) ?: 'neo4j';
        $endpoint = $baseUrl . '/db/' . rawurlencode($database) . '/tx/commit';
        $documentJobIds = $this->datasets->documentExternalIds($dataset);

        try {
            $response = $this->http->timeout(4)
                ->withBasicAuth((string) $this->config->get('config.neo4j_user', 'neo4j'), (string) $this->config->get('config.neo4j_password', ''))
                ->post($endpoint, [
                    'statements' => [[
                        'statement' => <<<'CYPHER'
MATCH (n)
WHERE n.dataset_id = $dataset_id
   OR n.dataset = $dataset_id
   OR n.namespace = $namespace
   OR n.neo4j_namespace = $namespace
   OR any(doc_id IN $document_job_ids WHERE doc_id IN coalesce(n.doc_ids, []))
WITH count(n) AS nodes
MATCH ()-[r]->()
WHERE r.dataset_id = $dataset_id
   OR r.dataset = $dataset_id
   OR r.namespace = $namespace
   OR r.neo4j_namespace = $namespace
   OR r.doc_id IN $document_job_ids
   OR any(doc_id IN $document_job_ids WHERE doc_id IN coalesce(r.doc_ids, []))
RETURN nodes, count(r) AS relationships
CYPHER,
                        'parameters' => [
                            'dataset_id' => $dataset->dataset_id,
                            'namespace' => $dataset->neo4j_namespace,
                            'document_job_ids' => $documentJobIds,
                        ],
                    ]],
                ]);

            if (!$response->successful()) {
                return [
                    'ok' => false,
                    'namespace' => $dataset->neo4j_namespace,
                    'nodes' => null,
                    'relationships' => null,
                    'error' => 'Neo4j HTTP ' . $response->status(),
                ];
            }

            $errors = $response->json('errors') ?? [];
            if ($errors !== []) {
                return [
                    'ok' => false,
                    'namespace' => $dataset->neo4j_namespace,
                    'nodes' => null,
                    'relationships' => null,
                    'error' => $errors[0]['message'] ?? 'Neo4j query failed.',
                ];
            }

            $row = $response->json('results.0.data.0.row') ?? [0, 0];

            return [
                'ok' => true,
                'namespace' => $dataset->neo4j_namespace,
                'nodes' => (int) ($row[0] ?? 0),
                'relationships' => (int) ($row[1] ?? 0),
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'namespace' => $dataset->neo4j_namespace,
                'nodes' => null,
                'relationships' => null,
                'error' => $exception->getMessage(),
            ];
        }
    }

    private function datasetId(mixed $value): string
    {
        return $this->stringValue($value) ?? 'default';
    }

    private function safeName(string $value): string
    {
        $safe = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($value))) ?: 'default';
        $safe = trim($safe, '_');

        return $safe !== '' ? $safe : 'default';
    }

    private function qdrantCollectionFor(string $safe): string
    {
        return 'hawki_' . $safe;
    }

    private function neo4jNamespaceFor(string $safe): string
    {
        return 'hawki_' . $safe;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
