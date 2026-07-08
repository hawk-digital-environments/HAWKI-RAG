<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Models\AssistantDocument;
use App\Services\Assistant\Repositories\AssistantDocumentRepository;
use App\Services\Pipeline\Uploads\PipelineUploadService;
use App\Services\Pipeline\Values\PipelineUploadInput;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\UploadedFile;

#[Singleton]
readonly class AssistantDocumentCreateService
{
    public function __construct(
        private AssistantDocumentRepository $documents,
        private AssistantDocumentSyncService $sync,
        private AssistantDocumentPayloadBuilder $payloads,
        private PipelineUploadService $uploads,
        private AssistantDocumentPipelineStateResolver $pipelineState,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function create(array $input, ?UploadedFile $file, ?string $idempotencyKey): array
    {
        $upload = $this->uploads->upload(
            $this->pipelineInput((string) ($input['dataset_id'] ?? ''), (bool) ($input['graph_enabled'] ?? false)),
            $file,
        );

        if (($upload->payload['success'] ?? false) !== true) {
            return [
                'status' => $upload->status,
                'payload' => $upload->payload,
            ];
        }

        $state = $this->pipelineState->resolve($upload->payload);
        $document = $this->documents->create([
            'assistant_document_id' => $this->documents->nextAssistantDocumentId(),
            'dataset_id' => (string) $input['dataset_id'],
            'display_name' => $input['display_name'] ?? $file?->getClientOriginalName(),
            'source_type' => 'upload',
            'source_url' => $input['source_url'] ?? null,
            'source_updated_at' => $input['source_updated_at'] ?? null,
            'source_checksum_sha256' => $input['source_checksum_sha256'] ?? $state->checksumSha256,
            'graph_enabled' => (bool) ($input['graph_enabled'] ?? false),
            'status' => $state->status,
            'last_error' => $state->lastError,
            'latest_source_id' => $state->sourceId,
            'latest_task_id' => $state->taskId,
            'latest_job_id' => $state->jobId,
            'latest_document_version' => $state->documentVersion,
            'indexed_at' => $state->indexedAt,
            'metadata_json' => $input['metadata_json'] ?? null,
        ]);

        $document = $this->sync->sync($document);

        return $this->acceptedPayload('create', $document);
    }

    /**
     * @param array<string, mixed> $input
     * @param list<UploadedFile> $files
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function createBatch(array $input, array $files, ?string $idempotencyKey): array
    {
        $items = [];
        $accepted = 0;
        $failed = 0;

        foreach ($files as $index => $file) {
            $result = $this->create($input, $file, $this->batchItemIdempotencyKey($idempotencyKey, $index));
            $success = ($result['payload']['success'] ?? false) === true;

            $success ? $accepted++ : $failed++;

            $items[] = [
                'index' => $index,
                'file_name' => $file->getClientOriginalName(),
                'http_status' => $result['status'],
                'success' => $success,
                'result' => $result['payload'],
            ];
        }

        return [
            'status' => $failed === 0 ? 202 : 207,
            'payload' => [
                'success' => $failed === 0,
                'operation' => [
                    'type' => 'batch_create',
                    'status' => $failed === 0 ? 'accepted' : 'partial_failure',
                ],
                'summary' => [
                    'total' => count($files),
                    'accepted' => $accepted,
                    'failed' => $failed,
                ],
                'items' => $items,
            ],
        ];
    }

    private function pipelineInput(string $datasetId, bool $graphEnabled): PipelineUploadInput
    {
        return PipelineUploadInput::fromValidated([
            'dataset_id' => $datasetId,
            'graph' => $graphEnabled ? 'true' : 'false',
        ]);
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function acceptedPayload(string $operationType, AssistantDocument $document): array
    {
        return [
            'status' => 202,
            'payload' => [
                'success' => true,
                'operation' => [
                    'type' => $operationType,
                    'status' => 'accepted',
                ],
                'document' => $this->payloads->build($document),
                'pipeline' => [
                    'task_id' => $document->latest_task_id,
                    'job_id' => $document->latest_job_id,
                    'source_id' => $document->latest_source_id,
                ],
            ],
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function batchItemIdempotencyKey(?string $idempotencyKey, int $index): ?string
    {
        $base = $this->stringValue($idempotencyKey);

        return $base === null ? null : $base.':'.$index;
    }
}
