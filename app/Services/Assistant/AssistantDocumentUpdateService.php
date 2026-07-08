<?php

declare(strict_types=1);

namespace App\Services\Assistant;

use App\Models\AssistantDocument;
use App\Services\Assistant\Repositories\AssistantDocumentOutputRepository;
use App\Services\Assistant\Repositories\AssistantDocumentRepository;
use App\Services\Assistant\Values\AssistantDocumentReplacementDecision;
use App\Services\Pipeline\Uploads\PipelineUploadService;
use App\Services\Pipeline\Values\PipelineUploadInput;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;

#[Singleton]
readonly class AssistantDocumentUpdateService
{
    public function __construct(
        private AssistantDocumentRepository $documents,
        private AssistantDocumentOutputRepository $outputs,
        private AssistantDocumentSyncService $sync,
        private AssistantDocumentPayloadBuilder $payloads,
        private AssistantDocumentOutputDeletionService $deletions,
        private PipelineUploadService $uploads,
        private AssistantDocumentPipelineStateResolver $pipelineState,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    public function update(string $assistantDocumentId, array $input, ?UploadedFile $file, ?string $idempotencyKey): ?array
    {
        $document = $this->documents->find($assistantDocumentId);
        if ($document === null) {
            return null;
        }

        $document = $this->sync->sync($document);
        $decision = $this->replacementDecision(
            $document,
            $input['source_updated_at'] ?? null,
            $input['source_checksum_sha256'] ?? null,
            (bool) ($input['force'] ?? false),
        );

        if (! $decision->replace) {
            $document = $this->documents->save($document, $this->nonContentUpdates($document, $input));

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'operation' => [
                        'type' => 'update',
                        'status' => AssistantDocument::STATUS_SKIPPED_UNCHANGED,
                        'reason' => $decision->reason,
                    ],
                    'document' => $this->payloads->build($document),
                ],
            ];
        }

        try {
            $activeOutputs = $this->outputs->activeForDocument($document->assistant_document_id);
            $this->deletions->deleteActiveOutputs($activeOutputs, $idempotencyKey);
            if ($activeOutputs->isNotEmpty()) {
                $this->outputs->deactivateActiveOutputs($document, Carbon::now());
            }
        } catch (\Throwable $exception) {
            $document = $this->documents->save($document, [
                'status' => AssistantDocument::STATUS_FAILED,
                'last_error' => $exception->getMessage(),
            ]);

            return $this->failurePayload($document, 'update', 502, 'Failed to delete the existing indexed document.', $exception->getMessage());
        }

        $graphEnabled = ($input['graph_provided'] ?? false)
            ? (bool) ($input['graph_enabled'] ?? false)
            : (bool) $document->graph_enabled;

        $upload = $this->uploads->upload(
            $this->pipelineInput($document->dataset_id, $graphEnabled),
            $file,
        );

        if (($upload->payload['success'] ?? false) !== true) {
            $document = $this->documents->save($document, [
                'status' => AssistantDocument::STATUS_FAILED,
                'last_error' => (string) ($upload->payload['message'] ?? 'Replacement upload failed.'),
            ]);

            return $this->failurePayload(
                $document,
                'update',
                $upload->status,
                (string) ($upload->payload['message'] ?? 'Replacement upload failed.'),
                $this->stringValue($upload->payload['error'] ?? null),
            );
        }

        $state = $this->pipelineState->resolve($upload->payload);
        $document = $this->documents->save($document, array_merge(
            $this->nonContentUpdates($document, $input, $file),
            [
                'source_updated_at' => $input['source_updated_at'] ?? $document->source_updated_at,
                'source_checksum_sha256' => $input['source_checksum_sha256'] ?? $state->checksumSha256 ?? $document->source_checksum_sha256,
                'graph_enabled' => $graphEnabled,
                'status' => $state->status,
                'last_error' => $state->lastError,
                'latest_source_id' => $state->sourceId,
                'latest_task_id' => $state->taskId,
                'latest_job_id' => $state->jobId,
                'latest_document_version' => $state->documentVersion,
                'indexed_at' => null,
                'deleted_at' => null,
            ],
        ));

        $document = $this->sync->sync($document);

        return $this->acceptedPayload($document);
    }

    /**
     * @return array<string, mixed>
     */
    private function nonContentUpdates(AssistantDocument $document, array $input, ?UploadedFile $file = null): array
    {
        $attributes = [];

        if (($input['display_name_provided'] ?? false) === true) {
            $attributes['display_name'] = $input['display_name'] ?? $file?->getClientOriginalName();
        }

        if (($input['source_url_provided'] ?? false) === true) {
            $attributes['source_url'] = $input['source_url'] ?? null;
        }

        if (($input['metadata_json_provided'] ?? false) === true) {
            $attributes['metadata_json'] = $input['metadata_json'] ?? null;
        } elseif ($document->exists && $file !== null && $document->display_name === null) {
            $attributes['display_name'] = $file->getClientOriginalName();
        }

        return $attributes;
    }

    private function replacementDecision(
        AssistantDocument $document,
        mixed $incomingSourceUpdatedAt,
        mixed $incomingChecksum,
        bool $force,
    ): AssistantDocumentReplacementDecision {
        if ($force) {
            return AssistantDocumentReplacementDecision::replace();
        }

        $incomingUpdatedAt = $incomingSourceUpdatedAt instanceof Carbon ? $incomingSourceUpdatedAt : null;
        if ($document->source_updated_at instanceof Carbon && $incomingUpdatedAt instanceof Carbon) {
            if ($incomingUpdatedAt->greaterThan($document->source_updated_at)) {
                return AssistantDocumentReplacementDecision::replace();
            }

            return AssistantDocumentReplacementDecision::skip('incoming source_updated_at is not newer than the stored document');
        }

        $storedChecksum = $this->stringValue($document->source_checksum_sha256);
        $candidateChecksum = $this->stringValue($incomingChecksum);
        if ($storedChecksum !== null && $candidateChecksum !== null && hash_equals($storedChecksum, $candidateChecksum)) {
            return AssistantDocumentReplacementDecision::skip('incoming source_checksum_sha256 matches the stored document');
        }

        return AssistantDocumentReplacementDecision::replace();
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
    private function acceptedPayload(AssistantDocument $document): array
    {
        return [
            'status' => 202,
            'payload' => [
                'success' => true,
                'operation' => [
                    'type' => 'update',
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

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function failurePayload(
        AssistantDocument $document,
        string $operationType,
        int $status,
        string $message,
        ?string $error = null,
    ): array {
        return [
            'status' => $status,
            'payload' => [
                'success' => false,
                'operation' => [
                    'type' => $operationType,
                    'status' => AssistantDocument::STATUS_FAILED,
                ],
                'message' => $message,
                'error' => $error,
                'document' => $this->payloads->build($document),
            ],
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
