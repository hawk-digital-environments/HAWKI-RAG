<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Uploads;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Dataset\DatasetService;
use App\Services\Pipeline\Clients\PythonTemporalBridgeClient;
use App\Services\Pipeline\Exceptions\PipelineUploadStorageException;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineJobCreationRepository;
use App\Services\Pipeline\Repositories\PipelineJobStateMutationRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Tasks\IngestSourceWorkflowPayloadFactory;
use App\Services\Pipeline\Tasks\PipelineTaskStatusRefresher;
use App\Services\Pipeline\Values\PipelineUploadInput;
use App\Services\Pipeline\Values\PipelineUploadResult;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
class PipelineUploadService
{
    public function __construct(
        private readonly DatasetService $datasets,
        private readonly PipelineTaskRepository $taskRepository,
        private readonly PipelineJobCreationRepository $jobCreation,
        private readonly PipelineUploadStorage $storage,
        private readonly PipelineUploadPolicy $policy,
        private readonly PipelineCustomConverterProfileWriter $customConverterProfiles,
        private readonly PipelineUploadIdentifierFactory $identifiers,
        private readonly PipelineUploadPayloadService $payloads,
        private readonly IngestSourceWorkflowPayloadFactory $workflowPayloads,
        private readonly IngestionSourceRepository $ingestionSources,
        private readonly PipelineJobStateMutationRepository $jobStates,
        private readonly PipelineTaskStatusRefresher $refresher,
        private readonly PythonTemporalBridgeClient $temporalBridge,
        private readonly PipelineUploadResultFactory $results,
        private readonly LoggerInterface $logger,
        private readonly DatabaseManager $database,
        private readonly ClockInterface $clock = new Clock,
    ) {}

    public function preflight(PipelineUploadInput $input, ?UploadedFile $file): ?PipelineUploadResult
    {
        if (! $file || ! $file->isValid() || ! is_file($file->getRealPath()) || ! is_readable($file->getRealPath())) {
            return $this->results->unreadableFile();
        }
        $extension = $this->storage->extensionFor($file);
        if (! $this->policy->supports($extension, $input)) {
            return $this->results->unsupportedFile($input);
        }

        return null;
    }

    public function upload(PipelineUploadInput $input, ?UploadedFile $file, ?string $managedReplacementTaskId = null, bool $prepareOnly = false): PipelineUploadResult
    {
        if ($managedReplacementTaskId !== null) {
            $existing = $this->resumeManagedUpload($managedReplacementTaskId, $prepareOnly);
            if ($existing !== null) {
                return $existing;
            }
        }
        if ($failure = $this->preflight($input, $file)) {
            return $failure;
        }
        $extension = $this->storage->extensionFor($file);

        $taskId = $managedReplacementTaskId ?? $this->identifiers->uploadTaskId();

        try {
            $storedUpload = $this->storage->store($taskId, $file, $extension);
        } catch (PipelineUploadStorageException $exception) {
            $this->logger->warning($exception->logMessage(), array_merge([
                'dataset_id' => $input->datasetId,
                'task_id' => $taskId,
                'error' => $exception->getMessage(),
            ], $exception->logContext()));

            return $this->results->storageFailure($input, $exception);
        }

        $sourceUrl = $this->identifiers->sourceUrl($storedUpload);
        $sourceId = $this->workflowPayloads->sourceId(
            $input->datasetId,
            $sourceUrl.'|'.$storedUpload->contentHash
                .(isset($input->requestMetadata['managed_document_id']) ? '|managed:'.$input->requestMetadata['managed_document_id'] : '')
                .($managedReplacementTaskId === null ? '' : '|'.$taskId),
        );
        $storage = $this->workflowPayloads->storagePaths($sourceId);

        try {
            $customConverterProfilePath = $this->customConverterProfiles->write($sourceId, $input, $storage);
        } catch (\Throwable $exception) {
            $this->logger->warning('Custom converter profile could not be prepared.', [
                'dataset_id' => $input->datasetId,
                'task_id' => $taskId,
                'source_id' => $sourceId,
                'error' => $exception->getMessage(),
            ]);

            return $this->results->customConverterProfileFailure($input, $exception);
        }

        $dataset = $this->datasets->ensure($input->datasetId);
        $now = $this->now();

        $task = $this->taskRepository->createUploadTask(
            $taskId,
            $dataset,
            $now,
            $this->payloads->taskMetadata($dataset, $input, $storedUpload, $customConverterProfilePath),
        );

        $jobId = $this->identifiers->ingestJobId($taskId, $sourceId);

        $source = $this->ingestionSources->upsertStarting($sourceId, [
            'source_url' => $sourceUrl,
            'task_id' => $task->task_id,
            'dataset_id' => $task->dataset_id,
            'content_hash' => $storedUpload->contentHash,
            'refresh_cadence' => null,
            'raw_storage_path' => $storage['raw'],
            'markdown_storage_path' => $storage['markdown'],
            'metadata' => [
                'request' => $task->metadata['request'] ?? [],
                'dataset' => $task->metadata['dataset'] ?? [],
                'upload' => [
                    'original_filename' => $storedUpload->originalName,
                    'target_name' => $storedUpload->targetName,
                    'local_path' => $storedUpload->localPath,
                    'content_hash' => $storedUpload->contentHash,
                    'extension' => $storedUpload->extension,
                ],
                'custom_converter' => $this->payloads->customConverterMetadata($input, $customConverterProfilePath),
            ],
        ]);

        $metadata = array_merge($this->payloads->jobMetadata($dataset, $input, $storedUpload, $customConverterProfilePath), [
            'reason' => 'Started upload IngestSourceWorkflow through Temporal.',
            'dataset_id' => $task->dataset_id,
            'source_id' => $sourceId,
            'raw_storage_path' => $storage['raw'],
            'markdown_storage_path' => $storage['markdown'],
        ]);

        $job = $this->jobCreation->createUploadIngestJob(
            $jobId,
            $task,
            $sourceId,
            $sourceUrl,
            $storedUpload,
            $now,
            $metadata,
        );

        $workflowId = $this->workflowPayloads->workflowId($sourceId);
        $workflowInput = $this->workflowPayloads->input($task, $job, $source);

        if (isset($input->requestMetadata['managed_document_id'])) {
            $source = $this->ingestionSources->recordPlannedWorkflow($source, $workflowId, $workflowInput);
            $job->forceFill(['temporal_workflow_id' => $workflowId])->save();
        }
        if ($prepareOnly) {
            $sourceMetadata = $source->metadata ?? [];
            $sourceMetadata['managed_dispatch'] = ['input' => $workflowInput, 'attempted' => false];
            $source->forceFill(['metadata' => $sourceMetadata, 'index_status' => 'pending'])->save();
            $job->forceFill(['status' => 'pending', 'index_status' => 'pending'])->save();

            return $this->preparedResult($task, $job);
        }
        if (isset($input->requestMetadata['managed_document_id'])) {
            $sourceMetadata = $source->metadata ?? [];
            $sourceMetadata['managed_dispatch']['attempted'] = true;
            $source->forceFill(['metadata' => $sourceMetadata])->save();
        }
        try {
            $execution = $this->temporalBridge->startIngestWorkflow($workflowInput, $workflowId, $managedReplacementTaskId !== null);
            $metadata['temporal'] = array_filter([
                'workflow_id' => $execution->workflowId,
                'run_id' => $execution->runId,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            $this->ingestionSources->markWorkflowStarted($source, $execution->workflowId, $execution->runId, null);
            $job = $this->jobStates->markTemporalStarted($job, $execution->workflowId, $execution->runId, null, $metadata);
        } catch (\Throwable $exception) {
            $this->ingestionSources->markFailed($source, $exception->getMessage());
            $job = $this->jobStates->markFailed(
                $job,
                'Unable to start Temporal ingest workflow: '.$exception->getMessage(),
                $this->now(),
            );
            if ($managedReplacementTaskId !== null) {
                return PipelineUploadResult::fromPayload(['success' => false, 'message' => 'Replacement startup is unconfirmed; resubmit the same PUT and file.', 'task_id' => $taskId], 502);
            }
        }

        $task = $this->refresher->recalculate($task);

        $this->logger->info('Pipeline controller upload handed to Temporal.', [
            'task_id' => $task->task_id,
            'job_id' => $job->job_id,
            'source_id' => $sourceId,
            'source_url' => $sourceUrl,
            'local_path' => $storedUpload->localPath,
            'workflow_id' => $job->temporal_workflow_id,
        ]);

        return $this->results->success($task, $job);
    }

    /** Redispatch committed managed preparation after startup uncertainty or a process crash. */
    public function dispatchPreparedManagedUpload(string $taskId): PipelineUploadResult
    {
        return $this->resumeManagedUpload($taskId) ?? throw new \RuntimeException('Managed upload preparation was not found.');
    }

    private function resumeManagedUpload(string $taskId, bool $prepareOnly = false): ?PipelineUploadResult
    {
        $task = $this->taskRepository->findWithOrderedJobs($taskId);
        if ($task === null) {
            return null;
        }
        $job = $task->jobs->first();
        $source = $job ? $this->ingestionSources->findBySourceId($job->source_id) : null;
        if (! $job || ! $source) {
            throw new \RuntimeException('Durable replacement upload has incomplete task/source state.');
        }
        if ($prepareOnly) {
            return $this->preparedResult($task, $job);
        }
        if ($this->database->connection()->transactionLevel() !== 0) {
            throw new \LogicException('Managed Temporal dispatch requires committed SQL preparation.');
        }
        if ($job->temporal_run_id) {
            return $this->results->success($task, $job);
        }
        $workflowId = $job->temporal_workflow_id;
        $workflowInput = $source->metadata['managed_dispatch']['input'] ?? null;
        if (! is_array($workflowInput) || ! $workflowId) {
            throw new \RuntimeException('Replacement dispatch input must be reconciled before startup.');
        }
        $path = $workflowInput['upload']['local_path'] ?? '';
        if (! is_file($path) || ! hash_equals((string) $source->content_hash, (string) hash_file('sha256', $path))) {
            return PipelineUploadResult::fromPayload(['success' => false, 'permanent' => ! ($source->metadata['managed_dispatch']['attempted'] ?? false), 'message' => 'Durable replacement file is missing or changed; restore its original bytes before retrying.'], 422);
        }
        $this->database->transaction(function () use ($job, $source): void {
            // Match the worker callback lock order: job, then source.
            $job->newQuery()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            $current = $this->ingestionSources->lockBySourceId($source->source_id);
            if (isset($current->metadata['managed_deletion']) || ($current->metadata['managed_writer_gate'] ?? null) === 'closed') {
                throw new \RuntimeException('Managed upload was fenced before dispatch.');
            }
            $metadata = $current->metadata ?? [];
            $metadata['managed_dispatch']['attempted'] = true;
            $current->forceFill(['metadata' => $metadata])->save();
        });
        try {
            $execution = $this->temporalBridge->startIngestWorkflow($workflowInput, $workflowId, true);
            $job = $this->database->transaction(function () use ($job, $source, $execution) {
                $current = $job->newQuery()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
                $this->ingestionSources->confirmWorkflowStarted($source, $execution->workflowId, $execution->runId);

                return $this->jobStates->confirmTemporalStarted($current, $execution->workflowId, $execution->runId, $current->metadata ?? []);
            });
        } catch (\Throwable $error) {
            return PipelineUploadResult::fromPayload(['success' => false, 'message' => 'Replacement startup is unconfirmed; resubmit the same PUT and file.', 'task_id' => $taskId], 502);
        }

        return $this->results->success($this->refresher->recalculate($task), $job);
    }

    private function preparedResult(PipelineTask $task, PipelineJob $job): PipelineUploadResult
    {
        // No status HTTP request: this method is safe inside the preparation transaction.
        return PipelineUploadResult::fromPayload(['success' => true, 'task_id' => $task->task_id, 'job_id' => $job->job_id, 'source_id' => $job->source_id], 202);
    }

    private function now(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
