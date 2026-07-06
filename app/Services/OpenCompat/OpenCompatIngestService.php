<?php

declare(strict_types=1);

namespace App\Services\OpenCompat;

use App\Services\Settings\SettingsService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;

#[Singleton]
readonly class OpenCompatIngestService
{
    public function __construct(
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
     * @return array{provider: string, graph_model: ?string, embedding_model: ?string}
     */
    private function modelRuntime(): array
    {
        return $this->settings->modelRuntime();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function collection(array $input): string
    {
        return $this->string(
            $input['collection']
                ?? $input['heap_id']
                ?? $input['heapId']
                ?? $input['dataset_id']
                ?? $input['datasetId']
                ?? null,
        ) ?? 'default';
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
