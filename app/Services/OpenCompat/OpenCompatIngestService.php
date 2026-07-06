<?php

declare(strict_types=1);

namespace App\Services\OpenCompat;

use App\Models\PipelineTask;
use App\Services\Pipeline\PipelineService;
use App\Services\Pipeline\Values\PipelineUploadInput;
use App\Services\Settings\SettingsService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

#[Singleton]
readonly class OpenCompatIngestService
{
    public function __construct(
        private PipelineService $pipeline,
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
     * @return array{payload: array<string, mixed>, status: int}
     */
    private function unsupported(string $endpoint, string $reason): array
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
