<?php

declare(strict_types=1);

namespace App\Services\OpenCompat;

use App\Models\PipelineTask;
use App\Services\Compatibility\LegacyDatasetService;
use App\Services\Document\DocumentBrowserService;
use App\Services\Pipeline\PipelineService;
use App\Services\Pipeline\Values\PipelineUploadInput;
use App\Services\Rag\RagStatsService;
use App\Services\Settings\SettingsService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[Singleton]
readonly class OpenCompatService
{
    public function __construct(
        private DocumentBrowserService $browser,
        private LegacyDatasetService $datasets,
        private PipelineService $pipeline,
        private RagStatsService $stats,
        private SettingsService $settings,
        private OpenCompatBridgeClient $bridge,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function ingestText(array $input, ?string $idempotencyKey = null): array
    {
        $documentId = $this->string($input['document_id'] ?? $input['documentId'] ?? $input['id'] ?? null) ?? (string) Str::uuid();
        $metadata = $this->array($input['metadata'] ?? []);
        $metadata['filename'] ??= $this->string($input['filename'] ?? $input['name'] ?? null) ?? $documentId.'.txt';

        $bridgePayload = [
            'docs' => [[
                'id' => $documentId,
                'text' => (string) $input['text'],
                'payload' => $metadata,
            ]],
            'provider' => $this->string($input['provider'] ?? null) ?? $this->modelRuntime()['provider'],
            'collection' => $this->collection($input),
            'graph' => filter_var($input['graph'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'chunk_chars' => (int) ($input['chunk_chars'] ?? $input['chunk_size'] ?? 1200),
            'chunk_overlap' => (int) ($input['chunk_overlap'] ?? 250),
        ];

        foreach (['embedding_model', 'distance', 'batch_size', 'graph_engine', 'graph_model', 'graph_only', 'dry_run'] as $field) {
            if (array_key_exists($field, $input)) {
                $bridgePayload[$field] = $input[$field];
            }
        }

        $result = $this->bridge->post('/ingest', $bridgePayload, $idempotencyKey);
        if ($result['status'] >= 400) {
            return ['status' => $result['status'], 'payload' => $this->errorPayload('ingest_failed', 'Bridge text ingest failed.', $result['payload'])];
        }

        return [
            'status' => 201,
            'payload' => [
                'document' => [
                    'id' => $documentId,
                    'filename' => $metadata['filename'],
                    'metadata' => $metadata,
                    'status' => 'completed',
                    'collection' => $bridgePayload['collection'],
                    'external_id' => $documentId,
                ],
                'bridge_response' => $result['payload'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function ingestFile(array $input, ?UploadedFile $file): array
    {
        if (! $file) {
            return ['status' => 422, 'payload' => $this->errorPayload('validation_error', 'A file upload is required.')];
        }

        $defaults = $this->settings->customConverterUploadDefaults();
        $uploadInput = PipelineUploadInput::fromValidated([
            'dataset_id' => $this->collection($input) ?? 'compat-uploads',
            'graph' => $input['graph'] ?? true,
            'converter_mode' => $input['converter_mode'] ?? $input['converterMode'] ?? null,
            'converter_url' => $input['converter_url'] ?? $input['converterUrl'] ?? null,
            'converter_token' => $input['converter_token'] ?? $input['converterToken'] ?? $this->settings->customConverterToken(),
            'converter_start_path' => $input['converter_start_path'] ?? $input['converterStartPath'] ?? null,
        ], $defaults);

        $result = $this->pipeline->uploads->upload($uploadInput, $file);

        return [
            'status' => $result->status,
            'payload' => [
                'document' => $this->queuedDocumentFromUpload($result->payload),
                'task' => $result->payload['task'] ?? null,
                'job' => $result->payload['job'] ?? null,
            ],
        ];
    }

    /**
     * @param list<UploadedFile> $files
     * @param array<string, mixed> $input
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function ingestFiles(array $files, array $input): array
    {
        $documents = [];
        foreach ($files as $file) {
            $documents[] = $this->ingestFile($input, $file)['payload']['document'] ?? null;
        }

        return [
            'status' => 202,
            'payload' => [
                'documents' => array_values(array_filter($documents)),
                'count' => count(array_filter($documents)),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function requeue(array $input): array
    {
        $jobIds = array_values(array_filter(array_map('strval', $this->array($input['job_ids'] ?? $input['jobIds'] ?? []))));
        if ($jobIds !== []) {
            return ['status' => 202, 'payload' => ['requeue' => $this->pipeline->recovery->retrySelected($jobIds)]];
        }

        $taskId = $this->string($input['task_id'] ?? $input['taskId'] ?? null);
        if ($taskId !== null) {
            return ['status' => 202, 'payload' => ['requeue' => $this->pipeline->recovery->retryTask($taskId)]];
        }

        return $this->unsupported('ingest/requeue', 'RAWKI can requeue failed pipeline jobs by job_ids or task_id; document filter requeue is not available.');
    }

    /**
     * @param array<string, mixed> $input
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function retrieveChunks(
        array $input,
        bool $grouped = false,
    ): array
    {
        $payload = [
            'query' => (string) ($input['query'] ?? ''),
            'top_k' => (int) ($input['top_k'] ?? $input['k'] ?? 5),
            'filters' => is_array($input['filters'] ?? null) ? $input['filters'] : [],
            'generate' => false,
            'fast_mode' => filter_var($input['fast_mode'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'smart_lookup' => filter_var($input['smart_lookup'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'preferred_tags' => $input['preferred_tags'] ?? null,
        ];

        $result = $this->bridge->post('/query', $payload);

        if ($result['status'] >= 400 || ! is_array($result['payload'])) {
            return ['status' => $result['status'], 'payload' => $this->errorPayload('retrieval_failed', 'Bridge retrieval failed.', $result['payload'])];
        }

        $chunks = $this->chunksFromHits($this->array($result['payload']['hits'] ?? []));
        if (! $grouped) {
            return ['status' => 200, 'payload' => ['chunks' => $chunks, 'count' => count($chunks)]];
        }

        $groups = [];
        foreach ($chunks as $chunk) {
            $documentId = (string) ($chunk['document_id'] ?? 'unknown');
            $groups[$documentId]['document_id'] = $documentId;
            $groups[$documentId]['chunks'][] = $chunk;
        }

        return ['status' => 200, 'payload' => ['groups' => array_values($groups), 'count' => count($groups)]];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function batchChunks(array $input): array
    {
        if ($this->string($input['query'] ?? null) === null) {
            return $this->unsupported('batch/chunks', 'RAWKI does not expose chunk lookup by external chunk IDs; provide query to use retrieval-backed chunks.');
        }

        return $this->retrieveChunks($input);
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function listFolders(int $limit = 100): array
    {
        $folders = array_map(fn (array $dataset): array => $this->folderShape($dataset), $this->datasets->list($limit));

        return ['status' => 200, 'payload' => ['folders' => $folders, 'count' => count($folders)]];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function createFolder(array $input): array
    {
        $dataset = $this->datasets->ensure($input['name'] ?? $input['folder_id'] ?? $input['folderId'] ?? null, [
            'name' => $input['name'] ?? null,
            'description' => $input['description'] ?? null,
        ]);

        return ['status' => 201, 'payload' => ['folder' => $this->folderShape($this->datasets->show($dataset->dataset_id) ?? [])]];
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function showFolder(string $folderId): array
    {
        $dataset = $this->datasets->show($folderId);
        if (! $dataset) {
            return ['status' => 404, 'payload' => $this->errorPayload('folder_not_found', 'Folder/dataset not found.')];
        }

        return ['status' => 200, 'payload' => ['folder' => $this->folderShape($dataset)]];
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function deleteFolder(string $folderId): array
    {
        return $this->unsupported('folders/delete', 'RAWKI datasets own vector and graph storage; folder deletion semantics are not safe to map to dataset deletion.');
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function models(): array
    {
        $payload = $this->settings->browserPayload();
        $runtime = $this->modelRuntime();
        $models = [];
        foreach ($payload['providers'] ?? [] as $provider) {
            if (! is_array($provider)) {
                continue;
            }
            $models[] = [
                'id' => $provider['key'].'::graph',
                'provider' => $provider['key'],
                'model_name' => $provider['defaultGraphModel'] ?? null,
                'type' => 'completion',
                'available' => (bool) ($provider['runtimeSupported'] ?? false),
            ];
            if (($provider['embeddingSupported'] ?? false) === true) {
                $models[] = [
                    'id' => $provider['key'].'::embedding',
                    'provider' => $provider['key'],
                    'model_name' => $provider['defaultEmbeddingModel'] ?? null,
                    'type' => 'embedding',
                    'available' => true,
                ];
            }
        }

        return ['status' => 200, 'payload' => ['models' => $models, 'default' => $runtime]];
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function apiKeys(): array
    {
        $providers = $this->settings->browserPayload()['providers'] ?? [];
        $keys = [];
        foreach ($providers as $provider) {
            if (! is_array($provider)) {
                continue;
            }
            $keys[] = [
                'provider' => $provider['key'],
                'api_url' => $provider['apiUrl'] ?? '',
                'has_key' => (bool) ($provider['apiKeySet'] ?? false),
                'api_key' => null,
            ];
        }

        return ['status' => 200, 'payload' => ['api_keys' => $keys]];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function saveApiKey(array $input): array
    {
        $provider = $this->string($input['provider'] ?? null);
        $apiKey = $this->string($input['api_key'] ?? $input['apiKey'] ?? null);
        if ($provider === null || $apiKey === null) {
            return ['status' => 422, 'payload' => $this->errorPayload('validation_error', 'provider and api_key are required.')];
        }

        $this->settings->update([
            'providerCredentials' => [
                $provider => [
                    'apiUrl' => $input['api_url'] ?? $input['apiUrl'] ?? null,
                    'apiKey' => $apiKey,
                ],
            ],
        ]);

        return ['status' => 201, 'payload' => ['provider' => $provider, 'has_key' => true, 'api_key' => null]];
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function logs(int $limit = 100): array
    {
        $path = storage_path('logs/laravel.log');
        $lines = is_file($path) ? array_slice(file($path, FILE_IGNORE_NEW_LINES) ?: [], -max(1, min(500, $limit))) : [];

        return [
            'status' => 200,
            'payload' => [
                'logs' => array_values(array_map(fn (string $line): array => ['message' => $line], $lines)),
                'count' => count($lines),
            ],
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function usage(): array
    {
        $stats = $this->stats->show();
        $documents = $this->browser->list(250);
        $bytes = 0;
        foreach ($documents as $document) {
            $bytes += (int) ($document['fileSize'] ?? 0);
        }

        return [
            'status' => 200,
            'payload' => [
                'storage' => [
                    'document_count' => count($documents),
                    'document_bytes' => $bytes,
                    'local_disk' => [
                        'root' => Storage::disk('local')->path(''),
                    ],
                ],
                'qdrant' => $stats['qdrant'] ?? null,
                'neo4j' => $stats['neo4j'] ?? null,
            ],
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function migrateDocument(): array
    {
        return $this->unsupported('migrate/document', 'RAWKI migration requires an uploaded source file or existing pipeline source; source-document migration is not available in this facade.');
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function unsupported(string $endpoint, string $reason): array
    {
        return [
            'status' => 501,
            'payload' => [
                'ok' => false,
                'error' => 'unsupported',
                'endpoint' => $endpoint,
                'reason' => $reason,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function queuedDocumentFromUpload(array $payload): array
    {
        $task = $this->array($payload['task'] ?? []);
        $job = $this->array($payload['job'] ?? []);

        return [
            'id' => $job['sourceId'] ?? $job['jobId'] ?? $task['taskId'] ?? null,
            'status' => $job['status'] ?? $task['status'] ?? PipelineTask::STATUS_PENDING,
            'task_id' => $task['taskId'] ?? null,
            'job_id' => $job['jobId'] ?? null,
            'metadata' => [
                'rawki_pipeline_payload' => $payload,
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $hits
     * @return list<array<string, mixed>>
     */
    private function chunksFromHits(array $hits): array
    {
        $chunks = [];
        foreach ($hits as $index => $hit) {
            if (! is_array($hit)) {
                continue;
            }
            $payload = $this->array($hit['payload'] ?? []);
            $documentId = $this->string($payload['doc_id'] ?? $payload['document_id'] ?? $payload['id'] ?? $hit['id'] ?? null);
            $chunks[] = [
                'id' => $this->string($hit['id'] ?? null) ?? ($documentId ? $documentId.':'.$index : (string) $index),
                'document_id' => $documentId,
                'content' => (string) ($payload['content'] ?? $payload['text'] ?? ''),
                'score' => $hit['score'] ?? null,
                'metadata' => $payload,
            ];
        }

        return $chunks;
    }

    /**
     * @param array<string, mixed> $dataset
     * @return array<string, mixed>
     */
    private function folderShape(array $dataset): array
    {
        return [
            'id' => $dataset['datasetId'] ?? null,
            'name' => $dataset['name'] ?? $dataset['datasetId'] ?? null,
            'description' => $dataset['description'] ?? null,
            'document_count' => $dataset['documentCount'] ?? 0,
            'created_at' => $dataset['createdAt'] ?? null,
            'metadata' => [
                'rawki_dataset_id' => $dataset['datasetId'] ?? null,
                'qdrant_collection' => $dataset['qdrantCollection'] ?? null,
                'neo4j_namespace' => $dataset['neo4jNamespace'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function collection(array $input): ?string
    {
        return $this->string($input['collection'] ?? $input['dataset_id'] ?? $input['datasetId'] ?? $input['folder_name'] ?? $input['folderName'] ?? null);
    }

    /**
     * @return array{provider: string, graph_model: ?string, embedding_model: ?string}
     */
    private function modelRuntime(): array
    {
        return $this->settings->modelRuntime();
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function errorPayload(string $error, string $message, mixed $details = null): array
    {
        return array_filter([
            'ok' => false,
            'error' => $error,
            'message' => $message,
            'details' => $details,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
