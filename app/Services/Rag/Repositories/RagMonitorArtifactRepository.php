<?php

declare(strict_types=1);

namespace App\Services\Rag\Repositories;

use App\Models\PipelineJob;
use App\Models\PipelineWorkerEventRecord;
use App\Models\RagGraphFailure;
use App\Models\RagIngestionArtifact;
use App\Services\Pipeline\Values\PipelineWorkerEvent;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

#[Singleton]
readonly class RagMonitorArtifactRepository
{
    public function store(
        PipelineWorkerEventRecord $record,
        PipelineWorkerEvent $event,
        PipelineJob $job,
        string $datasetId,
        array $monitor,
    ): RagIngestionArtifact {
        $artifact = RagIngestionArtifact::query()->createOrFirst(
            ['pipeline_worker_event_id' => $record->id],
            [
                'pipeline_job_id' => $job->id,
                'job_id' => $event->jobId,
                'task_id' => $event->taskId,
                'source_id' => $event->sourceId,
                'dataset_id' => $datasetId,
                'workflow_id' => $event->workflowId,
                'run_id' => $event->runId,
                'summary' => $monitor['summary'],
                'graph_preview' => $monitor['graph_preview'] ?? null,
                'occurred_at' => $event->occurredAt,
            ],
        );

        if (! $artifact->wasRecentlyCreated) {
            return $artifact;
        }

        foreach ($monitor['graph_failures'] ?? [] as $failure) {
            RagGraphFailure::query()->create([
                'rag_ingestion_artifact_id' => $artifact->id,
                'job_id' => $event->jobId,
                'source_id' => $event->sourceId,
                'dataset_id' => $datasetId,
                'document_id' => $this->nullableString($failure['doc_id'] ?? null),
                'error_code' => 'graph_extraction_failed',
                'message' => (string) $failure['error'],
                'context' => $failure,
                'occurred_at' => $failure['timestamp'] ?? $event->occurredAt,
            ]);
        }

        return $artifact;
    }

    public function latest(): ?RagIngestionArtifact
    {
        return RagIngestionArtifact::query()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }

    public function latestWithGraphPreview(): ?RagIngestionArtifact
    {
        return RagIngestionArtifact::query()
            ->whereNotNull('graph_preview')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
    }

    /** @return Collection<int, RagGraphFailure> */
    public function latestFailures(int $limit): Collection
    {
        return RagGraphFailure::query()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    public function pruneBefore(Carbon $cutoff): int
    {
        return RagIngestionArtifact::query()
            ->where('occurred_at', '<', $cutoff)
            ->delete();
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
