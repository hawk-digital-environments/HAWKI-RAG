<?php

declare(strict_types=1);

namespace App\Services\TextIngestion;

use App\Models\Dataset;
use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Services\Dataset\DatasetRepository;
use App\Services\Pipeline\Clients\PythonTemporalBridgeClient;
use App\Services\Pipeline\Repositories\IngestionSourceRepository;
use App\Services\Pipeline\Repositories\PipelineTaskRepository;
use App\Services\Pipeline\Repositories\PipelineTransactionRepository;
use App\Services\TextIngestion\Exceptions\TextIngestionDeletionException;
use App\Services\TextIngestion\Exceptions\TextIngestionNotFoundException;
use App\Services\TextIngestion\Exceptions\TextIngestionSourceBusyException;
use App\Services\TextIngestion\Values\TextIngestionDeletionResult;
use App\Services\TextIngestion\Values\TextIngestionDeletionTarget;
use App\Services\TextIngestion\Values\TextIngestionMode;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
final readonly class TextIngestionDeletionService
{
    public function __construct(
        private IngestionSourceRepository $sources,
        private DatasetRepository $datasets,
        private PipelineTaskRepository $tasks,
        private PipelineTransactionRepository $transactions,
        private PythonTemporalBridgeClient $temporal,
        private TextIngestionVectorDeletionService $vectors,
        private TextIngestionArtifactStorage $artifacts,
        private TextIngestionIdentifierFactory $identifiers,
        private LoggerInterface $logger,
        private ClockInterface $clock = new Clock,
    ) {}

    public function target(string $sourceId): TextIngestionDeletionTarget
    {
        $source = $this->requireDirectText($this->sources->findBySourceId($sourceId));

        return new TextIngestionDeletionTarget(
            sourceId: (string) $source->source_id,
            datasetId: (string) $source->dataset_id,
        );
    }

    public function delete(
        TextIngestionDeletionTarget $target,
        string $idempotencyKey,
    ): TextIngestionDeletionResult {
        $snapshot = $this->transactions->run(function () use ($target, $idempotencyKey): array {
            $source = $this->requireDirectText(
                $this->sources->lockBySourceId($target->sourceId),
                $target->datasetId,
            );

            if ($source->index_status === IngestionSource::STATUS_DELETED) {
                return ['source' => $source, 'replayed' => true, 'workflow_closed' => true];
            }

            if ($this->workflowStartIsUnconfirmed($source)) {
                throw TextIngestionSourceBusyException::forSource($target->sourceId);
            }

            $workflowClosed = $source->index_status === IngestionSource::STATUS_READY
                || data_get($source->metadata, 'text_deletion.workflow_closed') === true;
            $source = $this->sources->markDeletionRequested(
                $source,
                hash('sha256', $idempotencyKey),
                $this->now(),
                $workflowClosed,
            );

            return ['source' => $source, 'replayed' => false, 'workflow_closed' => $workflowClosed];
        });

        /** @var IngestionSource $source */
        $source = $snapshot['source'];
        if ($snapshot['replayed'] === true) {
            return new TextIngestionDeletionResult($target->sourceId, $target->datasetId, true);
        }

        $dataset = $this->datasets->findByDatasetId($target->datasetId);
        if (! $dataset instanceof Dataset) {
            throw TextIngestionNotFoundException::unavailable();
        }

        try {
            $workflowId = $this->optionalString($source->temporal_workflow_id);
            if ($snapshot['workflow_closed'] !== true && $workflowId !== null) {
                $this->temporal->cancelWorkflowAndWait(
                    $workflowId,
                    $this->optionalString(data_get($source->metadata, 'temporal.run_id')),
                );
                $source = $this->transactions->run(function () use ($target): IngestionSource {
                    $current = $this->requireDirectText(
                        $this->sources->lockBySourceId($target->sourceId),
                        $target->datasetId,
                    );

                    return $this->sources->markDeletionWorkflowClosed($current);
                });
            }

            $this->vectors->delete(
                $dataset,
                $target->sourceId,
                $this->identifiers->documentId($target->sourceId),
                $idempotencyKey,
            );
            $this->artifacts->deleteSourceArtifacts($target->sourceId);
            $this->transactions->run(function () use ($target): void {
                $source = $this->requireDirectText(
                    $this->sources->lockBySourceId($target->sourceId),
                    $target->datasetId,
                );
                $this->sources->markDeleted($source, $this->now());
            });
        } catch (TextIngestionNotFoundException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->logger->warning('Direct-text deletion did not complete.', [
                'source_id' => $target->sourceId,
                'dataset_id' => $target->datasetId,
                'exception' => $exception,
            ]);

            throw TextIngestionDeletionException::failed($exception);
        }

        $this->logger->info('Direct-text ingestion deleted.', [
            'source_id' => $target->sourceId,
            'dataset_id' => $target->datasetId,
        ]);

        return new TextIngestionDeletionResult($target->sourceId, $target->datasetId, false);
    }

    private function requireDirectText(
        ?IngestionSource $source,
        ?string $datasetId = null,
    ): IngestionSource {
        $metadata = is_array($source?->metadata) ? $source->metadata : [];
        if (
            ! $source instanceof IngestionSource
            || ($datasetId !== null && ! hash_equals((string) $source->dataset_id, $datasetId))
            || ! TextIngestionMode::isDirectText($metadata)
        ) {
            throw TextIngestionNotFoundException::unavailable();
        }

        return $source;
    }

    private function workflowStartIsUnconfirmed(IngestionSource $source): bool
    {
        if (! is_string($source->task_id) || $source->task_id === '') {
            return false;
        }

        $task = $this->tasks->findWithOrderedJobs($source->task_id);
        $job = $task?->jobs->first(
            static fn (PipelineJob $candidate): bool => hash_equals(
                (string) $candidate->source_id,
                (string) $source->source_id,
            ),
        );

        return $job !== null
            && $job->current_stage === 'temporal.workflow_starting'
            && trim((string) $job->temporal_run_id) === '';
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function now(): Carbon
    {
        return Carbon::instance(\DateTimeImmutable::createFromInterface($this->clock->now()));
    }
}
