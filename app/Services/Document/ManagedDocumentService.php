<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\ManagedDocument;
use App\Models\ManagedDocumentDeletion;
use App\Services\Document\Exceptions\ManagedDeletionException;
use App\Services\Document\Repositories\ManagedDocumentOutputRepository;
use App\Services\Document\Repositories\ManagedDocumentRepository;
use App\Services\Document\Values\ManagedDocumentId;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use App\Services\Pipeline\Uploads\PipelineUploadService;
use App\Services\Pipeline\Values\PipelineUploadInput;
use App\Services\Pipeline\Values\PipelineUploadResult;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class ManagedDocumentService
{
    public function __construct(
        private ManagedDocumentRepository $documents,
        private ManagedDocumentOutputRepository $outputs,
        private ManagedDocumentSyncService $sync,
        private ManagedDocumentPayloadBuilder $payloads,
        private ManagedDocumentOutputDeletionService $deletions,
        private PipelineUploadService $uploads,
        private ManagedDocumentPipelineStateResolver $pipelineState,
        private PipelineTransactionRepository $transactions,
        private ClockInterface $clock = new Clock,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{status:int,payload:array<string,mixed>}
     */
    public function create(array $input, ?UploadedFile $file, ?string $idempotencyKey): array
    {
        $managedDocumentId = $this->documents->nextManagedDocumentId();
        $pipelineInput = $this->pipelineInput(
            (string) ($input['dataset_id'] ?? ''),
            (bool) ($input['graph_enabled'] ?? false),
            $managedDocumentId,
        );
        $taskId = 'task_managed_create_'.$managedDocumentId->value;
        [$upload, $document] = $this->transactions->run(function () use ($pipelineInput, $file, $taskId, $managedDocumentId, $input): array {
            $upload = $this->uploads->upload($pipelineInput, $file, $taskId, true);

            if (($upload->payload['success'] ?? false) !== true) {
                return [$upload, null];
            }

            $state = $this->pipelineState->resolve($upload->payload);
            $document = $this->documents->create([
                'document_id' => $managedDocumentId->value,
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

            return [$upload, $document];
        });
        if ($document === null) {
            return ['status' => $upload->status, 'payload' => $upload->payload];
        }
        // Owner, task, source, job and dispatch input are now visible to fast workers.
        $upload = $this->uploads->dispatchPreparedManagedUpload($taskId);
        if (($upload->payload['success'] ?? false) !== true) {
            return ['status' => $upload->status, 'payload' => array_merge($upload->payload, ['document' => $this->payloads->build($document)])];
        }

        $document = $this->sync->sync($document);

        return $this->acceptedPayload('create', $document);
    }

    /**
     * Start a Pipeline Controller upload and register it for document browsing.
     */
    public function createFromPipelineUpload(PipelineUploadInput $input, ?UploadedFile $file): PipelineUploadResult
    {
        $managedDocumentId = $this->documents->nextManagedDocumentId();
        $input = $input->withManagedDocumentId($managedDocumentId);
        $taskId = 'task_managed_create_'.$managedDocumentId->value;
        [$upload, $document] = $this->transactions->run(function () use ($input, $file, $taskId, $managedDocumentId): array {
            $upload = $this->uploads->upload($input, $file, $taskId, true);

            if (($upload->payload['success'] ?? false) !== true) {
                return [$upload, null];
            }

            $state = $this->pipelineState->resolve($upload->payload);
            $document = $this->documents->create([
                'document_id' => $managedDocumentId->value,
                'dataset_id' => $input->datasetId,
                'display_name' => $file?->getClientOriginalName(),
                'source_type' => 'upload',
                'source_url' => $this->uploadSourceUrl($file),
                'source_checksum_sha256' => $state->checksumSha256,
                'graph_enabled' => $input->graph,
                'status' => $state->status,
                'last_error' => $state->lastError,
                'latest_source_id' => $state->sourceId,
                'latest_task_id' => $state->taskId,
                'latest_job_id' => $state->jobId,
                'latest_document_version' => $state->documentVersion,
                'indexed_at' => $state->indexedAt,
            ]);

            return [$upload, $document];
        });
        if ($document === null) {
            return $upload;
        }
        $upload = $this->uploads->dispatchPreparedManagedUpload($taskId);

        $document = $this->sync->sync($document);

        return PipelineUploadResult::fromPayload(array_merge($upload->payload, [
            'document_id' => $managedDocumentId->value,
            'document' => $this->payloads->build($document),
        ]), $upload->status);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<UploadedFile>  $files
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

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function list(int $limit = 100, array $filters = []): array
    {
        $limit = max(1, min(250, $limit));

        return $this->documents->list($filters, $limit)
            ->map(fn (ManagedDocument $document): array => $this->payloads->build($this->sync->sync($document), includeDetails: false))
            ->all();
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    public function show(string $managedDocumentId): ?array
    {
        $document = $this->documents->find($managedDocumentId);
        if ($document === null) {
            return null;
        }

        $document = $this->sync->sync($document);

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'document' => $this->payloads->build($document, includeDetails: true),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    public function update(string $managedDocumentId, array $input, ?UploadedFile $file, ?string $idempotencyKey): ?array
    {
        $document = $this->documents->find($managedDocumentId);
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

        if (! $decision['replace'] && ! $this->deletions->pending($managedDocumentId) && ! $this->deletions->hasRequest($managedDocumentId, $idempotencyKey)) {
            $document = $this->documents->save($document, $this->nonContentUpdates($document, $input));

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'operation' => [
                        'type' => 'update',
                        'status' => ManagedDocument::STATUS_SKIPPED_UNCHANGED,
                        'reason' => $decision['reason'],
                    ],
                    'document' => $this->payloads->build($document),
                ],
            ];
        }

        $graphEnabled = ($input['graph_provided'] ?? false) ? (bool) ($input['graph_enabled'] ?? false) : (bool) $document->graph_enabled;
        $pipelineInput = $this->pipelineInput($document->dataset_id, $graphEnabled, $document->documentId());
        if ($failure = $this->uploads->preflight($pipelineInput, $file)) {
            return ['status' => $failure->status, 'payload' => $failure->payload];
        }
        $requestHash = $this->deletions->requestHash(
            $input, $file->getClientOriginalName(), hash_file('sha256', $file->getRealPath()),
        );
        try {
            $operation = $this->deletions->prepare($document, 'update', $idempotencyKey, $requestHash);
            $this->deletions->prepareReplacement($operation, function (string $taskId) use ($pipelineInput, $file): array {
                $upload = $this->uploads->upload($pipelineInput, $file, $taskId, true);

                return ['status' => $upload->status, 'payload' => $upload->payload];
            });
            $operation = $operation->refresh();
        } catch (ManagedDeletionException $error) {
            if (isset($operation) && $operation->status === 'preparing') {
                $this->deletions->abandonReplacement($operation);
            }

            return $this->failurePayload($document, 'update', 409, $error->getMessage());
        }
        $operation = $this->deletions->dispatch($operation);
        if (! in_array($operation->status, ['completed', 'continued'], true)) {
            return $this->deletionPayload($operation);
        }

        try {
            return $this->deletions->continueUpdate($operation, function (string $taskId) use ($pipelineInput, $file): array {
                $upload = $this->uploads->upload($pipelineInput, $file, $taskId);

                return ['status' => $upload->status, 'payload' => $upload->payload];
            }, function (ManagedDocument $document, array $upload) use ($input, $file, $operation, $graphEnabled): array {
                if (($upload['payload']['success'] ?? false) !== true || ($upload['payload']['status'] ?? null) === 'failed') {
                    if ($upload['payload']['permanent'] ?? false) {
                        $this->deletions->abandonReplacement($operation);
                    }
                    $document = $this->documents->save($document, [
                        'status' => ManagedDocument::STATUS_FAILED,
                        'last_error' => (string) ($upload['payload']['message'] ?? 'Replacement upload failed.'),
                    ]);

                    return $this->failurePayload(
                        $document,
                        'update',
                        $upload['status'],
                        (string) ($upload['payload']['message'] ?? 'Replacement upload failed.'),
                        $this->stringValue($upload['payload']['error'] ?? null),
                    );
                }

                $state = $this->pipelineState->resolve($upload['payload']);
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

                return $this->acceptedPayload('update', $document);
            });
        } catch (ManagedDeletionException $error) {
            return $this->failurePayload($this->documents->find($managedDocumentId), 'update', 409, $error->getMessage());
        }
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}|null
     */
    public function delete(string $managedDocumentId, ?string $idempotencyKey): ?array
    {
        $document = $this->documents->find($managedDocumentId);
        if ($document === null) {
            return null;
        }

        $document = $this->sync->sync($document);
        try {
            $operation = $this->deletions->prepare($document, 'delete', $idempotencyKey);
        } catch (ManagedDeletionException $error) {
            return $this->failurePayload($document, 'delete', 409, $error->getMessage());
        }

        return $this->deletionPayload($this->deletions->dispatch($operation));
    }

    private function deletionPayload(ManagedDocumentDeletion $operation): array
    {
        $completed = $operation->status === 'completed';

        return [
            'status' => $completed ? 200 : ($operation->last_error ? 502 : 202),
            'payload' => [
                'success' => $operation->last_error === null,
                'operation' => [
                    'type' => $operation->purpose, 'status' => $operation->status,
                    'operation_id' => $operation->operation_id, 'workflow_id' => $operation->workflow_id,
                    'run_id' => $operation->run_id,
                    'recovery' => $operation->purpose === 'update' ? 'Resubmit the same PUT, file and Idempotency-Key to resume replacement.' : 'Repeat DELETE to resume the same operation.',
                ],
                'document' => $this->payloads->build($this->documents->find($operation->document_id)),
                'deletion' => ['bridge_documents_deleted' => $completed ? count($operation->workflow_input['targets']) : 0, 'results' => $operation->results],
                'error' => $operation->last_error,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nonContentUpdates(ManagedDocument $document, array $input, ?UploadedFile $file = null): array
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

    /**
     * @return array{replace:bool,reason:?string}
     */
    private function replacementDecision(
        ManagedDocument $document,
        mixed $incomingSourceUpdatedAt,
        mixed $incomingChecksum,
        bool $force,
    ): array {
        if ($force) {
            return [
                'replace' => true,
                'reason' => null,
            ];
        }

        $incomingUpdatedAt = $incomingSourceUpdatedAt instanceof Carbon ? $incomingSourceUpdatedAt : null;
        if ($document->source_updated_at instanceof Carbon && $incomingUpdatedAt instanceof Carbon) {
            if ($incomingUpdatedAt->greaterThan($document->source_updated_at)) {
                return [
                    'replace' => true,
                    'reason' => null,
                ];
            }

            return [
                'replace' => false,
                'reason' => 'incoming source_updated_at is not newer than the stored document',
            ];
        }

        $storedChecksum = $this->stringValue($document->source_checksum_sha256);
        $candidateChecksum = $this->stringValue($incomingChecksum);
        if ($storedChecksum !== null && $candidateChecksum !== null && hash_equals($storedChecksum, $candidateChecksum)) {
            return [
                'replace' => false,
                'reason' => 'incoming source_checksum_sha256 matches the stored document',
            ];
        }

        return [
            'replace' => true,
            'reason' => null,
        ];
    }

    private function pipelineInput(string $datasetId, bool $graphEnabled, ManagedDocumentId $managedDocumentId): PipelineUploadInput
    {
        return PipelineUploadInput::fromValidated([
            'dataset_id' => $datasetId,
            'graph' => $graphEnabled ? 'true' : 'false',
            'request_metadata' => $managedDocumentId->toRequestMetadata(),
        ]);
    }

    private function uploadSourceUrl(?UploadedFile $file): ?string
    {
        $originalName = trim((string) $file?->getClientOriginalName());

        return $originalName === '' ? null : 'upload://'.$originalName;
    }

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function acceptedPayload(string $operationType, ManagedDocument $document): array
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

    /**
     * @return array{status:int,payload:array<string,mixed>}
     */
    private function failurePayload(
        ManagedDocument $document,
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
                    'status' => ManagedDocument::STATUS_FAILED,
                ],
                'message' => $message,
                'error' => $error,
                'document' => $this->payloads->build($document),
            ],
        ];
    }

    private function now(): Carbon
    {
        return Carbon::instance($this->clock->now());
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
