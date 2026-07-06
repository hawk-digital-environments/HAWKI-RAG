<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Heap\HeapCatalogService;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineJobCreationRepository;
use App\Services\Pipeline\Repositories\PipelineJobStateMutationRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use App\Services\Pipeline\Clients\PythonTemporalBridgeClient;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineTaskStarter
{
    public function __construct(
        private HeapCatalogService $heaps,
        private PipelineTaskCounterService $counters,
        private PipelineTaskInputNormalizer $input,
        private PipelineTaskMetadataService $metadata,
        private PipelineTaskSourceResolver $sources,
        private IngestSourceWorkflowPayloadFactory $workflowPayloads,
        private IngestionSourceRepository $ingestionSources,
        private PipelineTaskRepository $taskRepository,
        private PipelineJobCreationRepository $jobCreation,
        private PipelineJobStateMutationRepository $jobStates,
        private PipelineTransactionRepository $transactions,
        private PipelineTaskStatusRefresher $refresher,
        private PythonTemporalBridgeClient $temporalBridge,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * @param array<string, mixed> $input
     */
    public function start(array $input): PipelineTask
    {
        $task = $this->transactions->run(function () use ($input): PipelineTask {
            $heap = $this->heaps->ensure($input);
            $task = $this->taskRepository->createRunningTask(
                $this->input->taskId($input),
                $heap,
                $this->now(),
                $this->counters->defaults(),
                [
                    'request' => $input,
                    'orchestration' => 'temporal',
                    'temporal' => [
                        'workflow_type' => config('temporal.workflow.type', 'IngestSourceWorkflow'),
                        'workflow_task_queue' => config('temporal.task_queues.workflow', 'rag-workflow-task-queue'),
                    ],
                    'heap' => $this->metadata->heap($heap),
                ],
            );

            foreach ($this->sources->resolve($input) as $url) {
                $this->createTemporalSourceWorkflow($task, $url, $input);
            }

            return $this->refresher->recalculate($task);
        });

        return $task;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function createTemporalSourceWorkflow(PipelineTask $task, string $url, array $input): PipelineJob
    {
        $contentHash = hash('sha256', $url);
        $sourceId = $this->workflowPayloads->sourceId((string) $task->dataset_id, $url);
        $jobId = 'ingest_'.substr(hash('sha256', $task->task_id.'|'.$sourceId), 0, 24);
        $storage = $this->workflowPayloads->storagePaths($sourceId);
        $cadence = $this->refreshCadence($input);
        $now = $this->now();

        $source = $this->ingestionSources->upsertStarting($sourceId, [
            'source_url' => $url,
            'task_id' => $task->task_id,
            'dataset_id' => $task->dataset_id,
            'content_hash' => $contentHash,
            'refresh_cadence' => $cadence,
            'raw_storage_path' => $storage['raw'],
            'markdown_storage_path' => $storage['markdown'],
            'metadata' => [
                'request' => $input,
                'heap' => is_array($task->metadata['heap'] ?? null) ? $task->metadata['heap'] : [],
                'refresh' => $this->refreshMetadata($input),
            ],
        ]);

        $metadata = array_merge($this->metadata->taskJob($task), [
            'reason' => 'Started IngestSourceWorkflow through Temporal.',
            'heap_id' => $task->dataset_id,
            'dataset_id' => $task->dataset_id,
            'source_id' => $sourceId,
            'raw_storage_path' => $storage['raw'],
            'markdown_storage_path' => $storage['markdown'],
            'refresh_cadence' => $cadence,
        ]);

        $job = $this->jobCreation->createTemporalSourceJob(
            $jobId,
            $task,
            $sourceId,
            $url,
            $contentHash,
            $now,
            $metadata,
        );

        $workflowId = $this->workflowPayloads->workflowId($sourceId);
        $workflowInput = $this->workflowPayloads->input($task, $job, $source);

        try {
            $execution = $this->temporalBridge->startIngestWorkflow($workflowInput, $workflowId);
            $scheduleId = null;

            if ($cadence !== null) {
                $scheduleId = $this->workflowPayloads->scheduleId($sourceId);
                $this->temporalBridge->upsertIngestSchedule($scheduleId, $workflowId, $cadence, $workflowInput);
            }

            $metadata['temporal'] = array_filter([
                'workflow_id' => $execution->workflowId,
                'run_id' => $execution->runId,
                'schedule_id' => $scheduleId,
            ], static fn (mixed $value): bool => $value !== null && $value !== '');

            $this->ingestionSources->markWorkflowStarted($source, $execution->workflowId, $execution->runId, $scheduleId);
            $job = $this->jobStates->markTemporalStarted($job, $execution->workflowId, $execution->runId, $scheduleId, $metadata);
        } catch (\Throwable $exception) {
            $this->ingestionSources->markFailed($source, $exception->getMessage());
            $job = $this->jobStates->markFailed(
                $job,
                'Unable to start Temporal ingest workflow: '.$exception->getMessage(),
                $this->now(),
            );
        }

        return $job;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function refreshCadence(array $input): ?string
    {
        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
        $value = $this->input->nullableString(
            $input['refresh_cadence']
                ?? $input['refreshCadence']
                ?? $input['cadence']
                ?? $metadata['refresh_cadence']
                ?? $metadata['refreshCadence']
                ?? $metadata['cadence']
                ?? null
        );

        return in_array($value, ['daily', 'weekly', 'monthly'], true) ? $value : null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function refreshMetadata(array $input): array
    {
        $metadata = is_array($input['metadata'] ?? null) ? $input['metadata'] : [];
        $refresh = is_array($metadata['refresh'] ?? null) ? $metadata['refresh'] : [];

        return array_merge($refresh, array_filter([
            'cadence' => $this->refreshCadence($input),
            'etag' => $metadata['etag'] ?? null,
            'last_modified' => $metadata['last_modified'] ?? $metadata['lastModified'] ?? null,
            'content_hash' => $metadata['content_hash'] ?? $metadata['contentHash'] ?? null,
            'document_version' => $metadata['document_version'] ?? $metadata['documentVersion'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    private function now(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
