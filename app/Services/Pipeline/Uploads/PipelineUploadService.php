<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Uploads;

use App\Services\Heap\HeapCatalogService;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
class PipelineUploadService
{
    public function __construct(
        private readonly HeapCatalogService $heaps,
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
        private readonly ClockInterface $clock = new Clock(),
    ) {}

    public function upload(PipelineUploadInput $input, ?UploadedFile $file): PipelineUploadResult
    {
        if (! $file || ! $file->isValid()) {
            return $this->results->unreadableFile();
        }

        $extension = $this->storage->extensionFor($file);

        if (! $this->policy->supports($extension, $input)) {
            return $this->results->unsupportedFile($input);
        }

        $taskId = $this->identifiers->uploadTaskId();

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
            $sourceUrl.'|'.$storedUpload->contentHash,
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

        $dataset = $this->heaps->ensure($input->datasetId);
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

        try {
            $execution = $this->temporalBridge->startIngestWorkflow($workflowInput, $workflowId);
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

    private function now(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
