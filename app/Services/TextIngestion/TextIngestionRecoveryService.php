<?php

declare(strict_types=1);

namespace App\Services\TextIngestion;

use App\Models\IngestionSource;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Dataset\DatasetService;
use App\Services\Pipeline\Clients\PythonTemporalBridgeClient;
use App\Services\Pipeline\Values\TemporalWorkflowExecution;
use App\Services\TextIngestion\Values\TextIngestionMode;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
final readonly class TextIngestionRecoveryService
{
    public function __construct(
        private DatasetService $datasets,
        private TextIngestionWorkflowPayloadFactory $payloads,
        private TextIngestionIdentifierFactory $identifiers,
        private PythonTemporalBridgeClient $temporal,
    ) {}

    public function supports(PipelineJob $job, IngestionSource $source): bool
    {
        return TextIngestionMode::isDirectText(
            is_array($job->metadata) ? $job->metadata : [],
            is_array($source->metadata) ? $source->metadata : [],
        );
    }

    public function isDeletionTombstone(IngestionSource $source): bool
    {
        return in_array($source->index_status, [
            IngestionSource::STATUS_DELETING,
            IngestionSource::STATUS_DELETED,
        ], true);
    }

    public function startOrReuse(
        PipelineTask $task,
        PipelineJob $job,
        IngestionSource $source,
        int $recoveryAttempt = 1,
    ): TemporalWorkflowExecution {
        $prepared = $this->prepare($task, $job, $source);

        return $this->temporal->startTextIngestWorkflow($prepared['input'], $prepared['workflow_id'], $recoveryAttempt);
    }

    /** @return array{workflow_id: string, input: array<string, mixed>} */
    public function prepare(PipelineTask $task, PipelineJob $job, IngestionSource $source): array
    {
        $dataset = $this->datasets->requireActive((string) $task->dataset_id);

        return [
            'workflow_id' => $this->identifiers->workflowId((string) $task->task_id),
            'input' => $this->payloads->createFromPersisted($dataset, $task, $job, $source),
        ];
    }
}
