<?php

declare(strict_types=1);

namespace App\Services\TextIngestion;

use App\Models\Dataset;
use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Dataset\DatasetService;
use App\Services\Pipeline\Clients\PythonTemporalBridgeClient;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineJobCreationRepository;
use App\Services\Pipeline\Repositories\PipelineJobStateMutationRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use App\Services\Pipeline\Tasks\PipelineTaskStatusRefresher;
use App\Services\TextIngestion\Exceptions\TextIngestionIdempotencyException;
use App\Services\TextIngestion\Exceptions\TextIngestionSourceBusyException;
use App\Services\TextIngestion\Exceptions\TextIngestionWorkflowStartException;
use App\Services\TextIngestion\Values\StoredTextArtifact;
use App\Services\TextIngestion\Values\TextIngestionInput;
use App\Services\TextIngestion\Values\TextIngestionResult;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
/**
 * Service Responsibility:
 * This is the main coordinator for direct-text ingestion.
 * It takes already validated text input and moves it through the application
 * until the indexing work has been safely handed to Temporal.
 * ==========================================================================
 * Basic Workflow:
 * 
 *   request
 *     -> check for an existing idempotent operation
 *     -> require an active dataset
 *     -> store the immutable text artifact
 *     -> create task, source, and job records
 *     -> start or reuse the Temporal workflow
 *     -> save the Temporal workflow/run information
 *     -> return the ingestion result
 * ==========================================================================
 * Usecase for idempotency key:
 * Clients may retry the same request because of network failures, timeouts,
 * or uncertain workflow startup.
 * The idempotency key is used to generate a deterministic task ID.
 * This lets the service recognize that the same logical ingestion already
 * exists instead of creating another independent operation.
 * ==========================================================================
 * Usecase for request_hash:
 * request_hash represents the complete direct-ingestion request.
 * When the same idempotency key is used again, the stored request hash is
 * compared with the new request.
 * 
 * Same key + same request:
 *   The existing ingestion can be replayed or resumed.
 * Same key + different request:
 *   The request is rejected as an idempotency conflict.
 * ==========================================================================
 * DATASET IMPORTANT NOTE:
 *
 * The dataset owns the configuration needed by the indexing pipeline,
 * including the embedding provider, embedding model, Qdrant collection,
 * and Neo4j namespace.
 * The caller does not choose these physical storage or model settings.
 * ==========================================================================
 * +++ Graph ingestion is always Fasle:
 * Direct-text ingestion currently indexes only into the normal vector
 * ingestion path.
 * Graph ingestion is intentionally disabled for this workflow and the
 * service stores `graph => false` in its internal metadata.
 * ==========================================================================
 * Database persistence consists of:
 *
 * One logical ingestion creates:
 *
 *   - a PipelineTask
 *   - an IngestionSource
 *   - a PipelineJob
 * They are created inside one database transaction so they are not left
 * partially persisted when one of the database writes fails.
 * ==========================================================================
 * Why is the artifact stored before the database transaction?
 *
 * The text artifact lives on the shared filesystem while the task, source,
 * and job live in the database.
 * Filesystem writes and database transactions cannot be committed together
 * as one atomic operation.
 * Because of that, it is possible for the artifact to be stored successfully
 * while database persistence fails.
 * ==========================================================================
 * What happens when artifact storage succeeds but database persistence fails?
 *
 * The artifact is marked for reconciliation.
 * This creates a durable signal that the filesystem and database may be out
 * of sync.
 * We do not immediately delete the artifact because another concurrent or
 * retried ingestion may legitimately reference the same immutable revision.
 * ==========================================================================
 * What happens after a successful retry?
 *
 * The reconciliation marker is cleared.
 *
 * How are concurrent requests handled?
 *
 * Task, source, job, and workflow identities are deterministic.
 * If another request creates the same operation first, the losing request
 * reloads the existing task and attempts to resume or replay it instead of
 * blindly creating another ingestion.
 * A source that is already being processed by another task is treated as busy
 * and cannot be replaced by a competing ingestion.
 * ==========================================================================
 * What does "resume" mean?
 *
 * A task may already exist while its Temporal workflow startup is still
 * uncertain. In that case, this service rebuilds the deterministic artifact and workflow
 * input and asks the Temporal bridge to start or reuse the same workflow.
 *
 * What does "replay" mean?
 *
 * If the existing task has already moved beyond workflow startup, the service
 * does not start another workflow. It returns the current state of the existing ingestion to the caller.
 * ==========================================================================
 * Why is the Temporal workflow ID deterministic?
 *
 * A deterministic workflow ID makes retries safe.
 *
 * If Laravel loses the response after Temporal has already started the
 * workflow, the next request can refer to the same workflow instead of
 * accidentally creating another one.
 *
 * What happens after Temporal starts successfully?
 *
 * The returned workflow ID and run ID are persisted on the source and job,
 * and the task status is recalculated.
 *
 * This confirms that Laravel and Temporal are referring to the same execution.
 *
 * What happens if Laravel cannot confirm Temporal startup?
 *
 * The service logs safe identifiers and returns a workflow-start exception.
 *
 * The caller can retry using the same idempotency key and deterministic
 * workflow identity.
 * requestMetadata() explicitly replaces the submitted text with null before
 * storing request metadata.
 * The actual document content lives in the immutable artifact storage.
 * ==========================================================================
 * This Service logs operational identifiers such as:
 *
 *   - task ID
 *   - job ID
 *   - source ID
 *   - workflow ID
 *  ==========================================================================
 * In short:
 *
 *   TextIngestionService owns the safe transition from
 *
 *   "validated direct-text request"
 *
 *   to
 *
 *   "durable ingestion state handed to Temporal".
 *  ==========================================================================
 */
#[Singleton]
final readonly class TextIngestionService
{
    public function __construct(
        private DatasetService $datasets,
        private TextIngestionArtifactStorage $storage,
        private TextIngestionIdentifierFactory $identifiers,
        private TextIngestionWorkflowPayloadFactory $payloads,
        private PipelineTaskRepository $tasks,
        private PipelineJobCreationRepository $jobs,
        private IngestionSourceRepository $sources,
        private PipelineJobStateMutationRepository $jobStates,
        private PipelineTransactionRepository $transactions,
        private PipelineTaskStatusRefresher $taskStatus,
        private PythonTemporalBridgeClient $temporal,
        private LoggerInterface $logger,
        private ClockInterface $clock = new Clock,
    ) {}

    /**
     * Persist and start one idempotent direct-text ingestion.
     *
     * 1. Replay or resume the deterministic task when the key already exists.
     * 2. Store an immutable Markdown revision using server-owned dataset scope.
     * 3. Create the task, source, and job together with the future workflow ID.
     * 4. Start or reuse the Temporal workflow and confirm its returned run ID.
     */
    public function ingest(TextIngestionInput $input, string $idempotencyKey): TextIngestionResult
    {
        $taskId = $this->identifiers->taskId($input->datasetId, $idempotencyKey);
        $requestHash = hash('sha256', json_encode($input->toArray(), JSON_THROW_ON_ERROR));
        $existing = $this->tasks->findWithOrderedJobs($taskId);
        if ($existing) {
            return $this->resumeOrReplay($input, $existing, $idempotencyKey, $requestHash);
        }

        $dataset = $this->datasets->requireActive($input->datasetId);
        $artifact = $this->storage->store($input);
        $jobId = $this->identifiers->jobId($taskId, $artifact->sourceId);
        $workflowId = $this->identifiers->workflowId($taskId);
        $now = Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
        $requestMetadata = $this->requestMetadata($input, $idempotencyKey);
        $datasetMetadata = $this->datasetMetadata($dataset);
        $jobMetadata = $this->jobMetadata($artifact, $requestMetadata, $datasetMetadata);

        try {
            [$task, $source, $job] = $this->transactions->run(function () use (
                $artifact,
                $dataset,
                $datasetMetadata,
                $input,
                $jobId,
                $jobMetadata,
                $now,
                $requestHash,
                $requestMetadata,
                $taskId,
                $workflowId,
            ): array {
                $this->ensureSourceIsAvailable($artifact->sourceId, $taskId);
                $task = $this->tasks->createRunningTask($taskId, $dataset, $now, [], [
                    'request' => $requestMetadata,
                    'dataset' => $datasetMetadata,
                    'source_id' => $artifact->sourceId,
                    'request_hash' => $requestHash,
                ]);
                $source = $this->sources->upsertStarting($artifact->sourceId, [
                    'source_url' => $artifact->sourceUrl,
                    'task_id' => $taskId,
                    'dataset_id' => $input->datasetId,
                    'content_hash' => $artifact->contentHash,
                    'refresh_cadence' => null,
                    'temporal_workflow_id' => $workflowId,
                    'raw_storage_path' => null,
                    'markdown_storage_path' => dirname($artifact->markdownPath),
                    'metadata' => [
                        'request' => $requestMetadata,
                        'dataset' => $datasetMetadata,
                        'text_ingestion' => [
                            'external_document_id' => $input->externalDocumentId,
                            'content_format' => $input->contentFormat->value,
                            'markdown_path' => $artifact->markdownPath,
                        ],
                        'graph' => false,
                        'temporal' => [
                            'workflow_id' => $workflowId,
                        ],
                    ],
                ]);
                $job = $this->jobs->createTextIngestJob(
                    $jobId,
                    $task,
                    $artifact->sourceId,
                    $artifact->sourceUrl,
                    $artifact->markdownPath,
                    $artifact->contentHash,
                    $workflowId,
                    $now,
                    $jobMetadata,
                );

                return [$task, $source, $job];
            });
        } catch (QueryException $exception) {
            $existing = $this->tasks->findWithOrderedJobs($taskId);
            if (! $existing) {
                $this->markArtifactForReconciliation($artifact, $exception);
                $this->ensureSourceIsAvailable($artifact->sourceId, $taskId);
                throw $exception;
            }

            try {
                $result = $this->resumeOrReplay($input, $existing, $idempotencyKey, $requestHash);
            } catch (\Throwable $resumeException) {
                $this->markArtifactForReconciliation($artifact, $resumeException);

                throw $resumeException;
            }

            $this->storage->clearReconciliationMarker($artifact);

            return $result;
        } catch (\Throwable $exception) {
            $this->markArtifactForReconciliation($artifact, $exception);

            throw $exception;
        }

        $this->storage->clearReconciliationMarker($artifact);

        return $this->startWorkflow(
            $input,
            $artifact,
            $dataset,
            $task,
            $source,
            $job,
            $jobMetadata,
            replayed: false,
        );
    }

    private function ensureSourceIsAvailable(string $sourceId, string $taskId): void
    {
        $source = $this->sources->lockBySourceId($sourceId);
        if (
            $source
            && $source->index_status === IngestionSource::STATUS_RUNNING
            && ! hash_equals((string) $source->task_id, $taskId)
        ) {
            throw TextIngestionSourceBusyException::forSource($sourceId);
        }
    }

    private function markArtifactForReconciliation(
        StoredTextArtifact $artifact,
        \Throwable $persistenceException,
    ): void {
        try {
            $this->storage->markForReconciliation($artifact);
        } catch (\Throwable $markerException) {
            $this->logger->critical('Direct text artifact could not be marked for reconciliation.', [
                'source_id' => $artifact->sourceId,
                'markdown_path' => $artifact->markdownPath,
                'persistence_exception' => $persistenceException::class,
                'exception' => $markerException,
            ]);
        }
    }

    private function resumeOrReplay(
        TextIngestionInput $input,
        PipelineTask $task,
        string $idempotencyKey,
        string $requestHash,
    ): TextIngestionResult {
        if (($task->metadata['request_hash'] ?? null) !== $requestHash) {
            throw TextIngestionIdempotencyException::payloadMismatch($idempotencyKey);
        }

        /** @var PipelineJob|null $job */
        $job = $task->jobs->first();
        if (! $job) {
            throw TextIngestionIdempotencyException::incompleteTask($task->task_id);
        }
        if (
            $job->temporal_workflow_id
            && $job->current_stage !== 'temporal.workflow_starting'
        ) {
            return $this->replayedResult($task, $job);
        }

        $source = $this->sources->findBySourceId((string) $job->source_id);
        if (! $source) {
            throw TextIngestionIdempotencyException::incompleteSource((string) $job->source_id);
        }

        $dataset = $this->datasets->requireActive($input->datasetId);
        $artifact = $this->storage->store($input);

        return $this->startWorkflow(
            $input,
            $artifact,
            $dataset,
            $task,
            $source,
            $job,
            is_array($job->metadata) ? $job->metadata : [],
            replayed: true,
        );
    }

    /**
     * @param  array<string, mixed>  $jobMetadata
     */
    private function startWorkflow(
        TextIngestionInput $input,
        StoredTextArtifact $artifact,
        Dataset $dataset,
        PipelineTask $task,
        IngestionSource $source,
        PipelineJob $job,
        array $jobMetadata,
        bool $replayed,
    ): TextIngestionResult {
        $workflowId = $this->identifiers->workflowId($task->task_id);
        $workflowInput = $this->payloads->create(
            $input,
            $artifact,
            $dataset,
            $task->task_id,
            $job->job_id,
        );

        try {
            $execution = $this->temporal->startTextIngestWorkflow($workflowInput, $workflowId);
            $this->transactions->run(function () use ($execution, $job, $jobMetadata, $source, $task): void {
                $this->sources->confirmWorkflowStarted($source, $execution->workflowId, $execution->runId);
                $this->jobStates->confirmTemporalStarted(
                    $job,
                    $execution->workflowId,
                    $execution->runId,
                    $jobMetadata,
                );
                $this->taskStatus->recalculate($task);
            });
        } catch (\Throwable $exception) {
            $this->logger->warning('Direct text ingestion workflow start could not be confirmed.', [
                'task_id' => $task->task_id,
                'job_id' => $job->job_id,
                'source_id' => $artifact->sourceId,
                'workflow_id' => $workflowId,
                'exception' => $exception,
            ]);

            throw TextIngestionWorkflowStartException::fromPrevious($exception);
        }

        $this->logger->info('Direct text ingestion handed to Temporal.', [
            'task_id' => $task->task_id,
            'job_id' => $job->job_id,
            'source_id' => $artifact->sourceId,
            'workflow_id' => $execution->workflowId,
        ]);

        if ($replayed) {
            return TextIngestionResult::replayed(
                $task->task_id,
                $job->job_id,
                $artifact->sourceId,
                $execution->workflowId,
                'running',
            );
        }

        return TextIngestionResult::started(
            $task->task_id,
            $job->job_id,
            $artifact->sourceId,
            $execution->workflowId,
        );
    }

    private function replayedResult(PipelineTask $task, PipelineJob $job): TextIngestionResult
    {
        return TextIngestionResult::replayed(
            $task->task_id,
            $job->job_id,
            (string) $job->source_id,
            $job->temporal_workflow_id,
            (string) ($job->index_status ?: $job->status),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requestMetadata(TextIngestionInput $input, string $idempotencyKey): array
    {
        return [
            ...$input->toArray(),
            'text' => null,
            'idempotency_key' => $idempotencyKey,
            'graph' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function datasetMetadata(Dataset $dataset): array
    {
        return [
            'embedding_provider' => $dataset->embedding_provider,
            'embedding_model' => $dataset->embedding_model,
            'qdrant_collection' => $dataset->qdrant_collection,
            'neo4j_namespace' => $dataset->neo4j_namespace,
        ];
    }

    /**
     * @param  array<string, mixed>  $requestMetadata
     * @param  array<string, mixed>  $datasetMetadata
     * @return array<string, mixed>
     */
    private function jobMetadata(
        StoredTextArtifact $artifact,
        array $requestMetadata,
        array $datasetMetadata,
    ): array {
        return [
            'request' => $requestMetadata,
            'dataset' => $datasetMetadata,
            'source_id' => $artifact->sourceId,
            'markdown_path' => $artifact->markdownPath,
            'graph' => false,
        ];
    }
}
