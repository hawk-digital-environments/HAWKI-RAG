<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\Dataset;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Dataset\DatasetRepository;
use App\Services\Dataset\Exceptions\DatasetInactiveException;
use App\Services\Document\ManagedDocumentRecoveryGuard;
use App\Services\Pipeline\Clients\PythonTemporalBridgeClient;
use App\Services\Pipeline\Recovery\PipelineRecoveryMetadataService;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineJobRecoveryRepository;
use App\Services\Pipeline\Repositories\PipelineJobStateMutationRepository;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use App\Services\Pipeline\Tasks\IngestSourceWorkflowPayloadFactory;
use App\Services\TextIngestion\TextIngestionRecoveryService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

/** Shared reservation for task retry and recovery; Python retains the dispatch ledger. */
#[Singleton]
readonly class PipelineRecoveryWorkflowService
{
    public function __construct(
        private PipelineTransactionRepository $transactions,
        private PipelineJobRecoveryRepository $jobs,
        private PipelineJobStateMutationRepository $states,
        private IngestionSourceRepository $sources,
        private IngestSourceWorkflowPayloadFactory $payloads,
        private TextIngestionRecoveryService $text,
        private ManagedDocumentRecoveryGuard $managed,
        private PythonTemporalBridgeClient $control,
        private PipelineRecoveryMetadataService $metadata,
        private DatasetRepository $datasets,
        private ClockInterface $clock = new Clock,
    ) {}

    public function hasPending(PipelineJob $job): bool
    {
        $pending = $job->metadata['pending_recovery'] ?? null;

        return is_array($pending) && $pending['state'] !== 'confirmed';
    }

    /** Serialize attempt allocation across both entry points, including stale callers. */
    public function reserve(PipelineJob $job): ?PipelineJob
    {
        return $this->transactions->run(function () use ($job): ?PipelineJob {
            $current = $this->jobs->lockForRecovery($job);
            if (! $current || ($current->status !== PipelineJob::STATUS_FAILED && ! $this->hasPending($current))) {
                return null;
            }
            if ($this->hasPending($current)) {
                return $current;
            }
            $metadata = $current->metadata ?? [];
            $attempt = (int) ($metadata['retry_count'] ?? 0) + 1;
            $source = $this->sources->lockBySourceId((string) $current->source_id);
            $metadata['retry_count'] = $attempt;
            $metadata['retried_at'] = $this->clock->now()->format(\DateTimeInterface::ATOM);
            $metadata['pending_recovery'] = [
                'attempt' => $attempt,
                'predecessor_workflow_id' => $current->temporal_workflow_id,
                'workflow_id' => null,
                'workflow_type' => $source && $this->text->supports($current, $source) ? 'IngestTextWorkflow' : 'IngestSourceWorkflow',
                'state' => 'reserved',
            ];

            return $this->jobs->savePreparation($current, $metadata);
        });
    }

    public function start(PipelineTask $task, PipelineJob $job): PipelineJob
    {
        try {
            $job = $this->prepare($task, $job);
            $pending = $job->metadata['pending_recovery'];
            if ($pending['state'] === 'confirmed') {
                return $job;
            }
            // This committed identity and payload are used even after a lost response.
            $execution = $pending['workflow_type'] === 'IngestTextWorkflow'
                ? $this->control->startTextIngestWorkflow($pending['input'], $pending['workflow_id'], $pending['attempt'])
                : $this->control->startIngestWorkflow($pending['input'], $pending['workflow_id']);

            return $this->transactions->run(function () use ($job, $pending, $execution): PipelineJob {
                $current = $this->jobs->lockForRecovery($job);
                $record = $current->metadata['pending_recovery'];
                if ($record['attempt'] !== $pending['attempt'] || $record['state'] === 'confirmed') {
                    return $current;
                }
                $source = $this->sources->lockBySourceId((string) $current->source_id);
                $this->sources->markWorkflowStarted($source, $execution->workflowId, $execution->runId, $current->temporal_schedule_id);
                $metadata = $current->metadata ?? [];
                $metadata['pending_recovery'] = [...$record, 'state' => 'confirmed', 'run_id' => $execution->runId];
                $metadata['temporal'] = array_filter([
                    'workflow_id' => $execution->workflowId, 'run_id' => $execution->runId,
                    'schedule_id' => $current->temporal_schedule_id,
                ], static fn (mixed $value): bool => $value !== null && $value !== '');
                $current = $this->jobs->savePreparation($current, $metadata);

                return $this->states->confirmTemporalStarted($current, $execution->workflowId, $execution->runId, $metadata);
            });
        } catch (\Throwable $error) {
            $this->failed($job, $error);
            throw $error;
        }
    }

    /** Never let a delayed failure erase a concurrent confirmation or later attempt. */
    public function failed(PipelineJob $job, \Throwable $error): PipelineJob
    {
        return $this->transactions->run(function () use ($job, $error): PipelineJob {
            $current = $this->jobs->lockForRecovery($job);
            $pending = $current->metadata['pending_recovery'] ?? null;
            if ($pending && ($pending['state'] === 'confirmed'
                || $pending['attempt'] !== ($job->metadata['pending_recovery']['attempt'] ?? null))) {
                return $current;
            }
            if ($current->status === PipelineJob::STATUS_FAILED && $current->error_message === $error->getMessage()) {
                return $current;
            }
            $metadata = $this->metadata->publishFailedJobMetadata($current, $error);
            if ($pending) {
                $metadata['pending_recovery'] = [...$pending, 'state' => $pending['workflow_id'] ? 'uncertain' : 'reserved'];
            }
            if ($current->status === PipelineJob::STATUS_COMPLETED || $current->index_status === 'ready') {
                return $this->jobs->savePreparation($current, $metadata);
            }

            return $this->jobs->markRecoveryPublishFailed($current, $error->getMessage(), Carbon::instance($this->clock->now()), $metadata);
        });
    }

    private function prepare(PipelineTask $task, PipelineJob $job): PipelineJob
    {
        if ($this->datasets->findByDatasetId((string) $task->dataset_id)?->status !== Dataset::STATUS_ACTIVE) {
            throw DatasetInactiveException::forId((string) $task->dataset_id);
        }
        $pending = $job->metadata['pending_recovery'];
        $source = $this->sources->findBySourceId((string) $job->source_id);
        if (! $source) {
            throw new \RuntimeException('Ingestion source was not found.');
        }
        $directText = $pending['workflow_type'] === 'IngestTextWorkflow';
        if ($directText && $this->text->isDeletionTombstone($source)) {
            throw new \RuntimeException('Deleted direct-text ingestions cannot be recovered.');
        }
        if (isset($source->metadata['managed_deletion']) || ($source->metadata['managed_writer_gate'] ?? null) === 'closed') {
            throw new \RuntimeException('Managed recovery was fenced; reconcile before retrying.');
        }
        if (! empty($pending['input'])) {
            return $job;
        }
        $this->managed->verify($source); // Temporal inspection stays outside SQL.
        $input = $directText ? null : $this->payloads->retryInput($task, $job, $source);
        $prepared = $directText ? $this->text->prepare($task, $job, $source) : [
            'workflow_id' => $this->control->sourceRetryIdentity($source->source_id, $pending['predecessor_workflow_id'], $pending['attempt'], (string) $job->job_id),
            'input' => $input,
        ];

        return $this->transactions->run(function () use ($task, $job, $source, $prepared, $directText, $pending): PipelineJob {
            $current = $this->jobs->lockForRecovery($job);
            $record = $current->metadata['pending_recovery'];
            if ($record['attempt'] !== $pending['attempt']) {
                throw new \RuntimeException('Recovery attempt was superseded.');
            }
            if (! empty($record['input'])) {
                return $current; // Another caller already committed this exact attempt.
            }
            $attributes = [
                'source_url' => $source->source_url, 'task_id' => $task->task_id,
                'dataset_id' => $task->dataset_id, 'refresh_cadence' => $source->refresh_cadence,
                'raw_storage_path' => $source->raw_storage_path, 'markdown_storage_path' => $source->markdown_storage_path,
                'metadata' => array_merge($source->metadata ?? [], ['retried_at' => $current->metadata['retried_at']]),
            ];
            $input = $prepared['input'];
            if (isset($source->metadata['managed_dispatch'])) {
                $reserved = $this->sources->reserveManagedRecovery($source, $current, $prepared['workflow_id'], $input, $attributes);
                $input = $reserved->metadata['managed_dispatch']['input'];
            } else {
                $this->sources->upsertStarting($source->source_id, $attributes);
            }
            $metadata = $current->metadata ?? [];
            $metadata['pending_recovery'] = [
                ...$record, 'workflow_id' => $prepared['workflow_id'],
                'workflow_type' => $directText ? 'IngestTextWorkflow' : 'IngestSourceWorkflow',
                'input' => $input, 'input_hash' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)),
                'state' => 'dispatching',
            ];

            return $this->jobs->savePreparation($current, $metadata, [
                'temporal_workflow_id' => $prepared['workflow_id'], 'temporal_run_id' => null,
                'current_stage' => 'temporal.workflow_starting',
            ]);
        });
    }
}
