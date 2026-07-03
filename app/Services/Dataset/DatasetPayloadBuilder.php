<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use App\Models\Dataset;
use App\Models\Document;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class DatasetPayloadBuilder
{
    public function __construct(
        private DatasetRepository $datasets,
        private DatasetVectorStatsService $vectorStats,
        private DatasetGraphStatsService $graphStats,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Dataset $dataset, bool $includeDetails): array
    {
        $stats = $this->stats($dataset);
        $payload = [
            'id' => $dataset->id,
            'datasetId' => $dataset->dataset_id,
            'heapId' => $dataset->dataset_id,
            'name' => $dataset->name,
            'description' => $dataset->description,
            'status' => $dataset->status,
            'tenantId' => $dataset->tenant_id,
            'ownerApp' => $dataset->owner_application_id,
            'visibility' => $dataset->visibility,
            'protected' => (bool) $dataset->protected,
            'metadata' => $dataset->metadata_json ?? [],
            'qdrantCollection' => $dataset->qdrant_collection,
            'neo4jNamespace' => $dataset->neo4j_namespace,
            'createdAt' => $dataset->created_at?->format(DATE_ATOM),
            'updatedAt' => $dataset->updated_at?->format(DATE_ATOM),
            'documentCount' => $stats['documents'],
            'taskCount' => $stats['tasks'],
            'lastIngestion' => $stats['lastIngestion'],
            'graphStats' => $stats['graph'],
        ];

        if ($includeDetails) {
            $payload['tasks'] = $this->tasks($dataset);
            $payload['documents'] = $this->documents($dataset);
            $payload['ingestionHistory'] = $this->ingestionHistory($dataset);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(Dataset $dataset): array
    {
        return [
            'documents' => $this->datasets->documentCount($dataset),
            'tasks' => $this->datasets->taskCount($dataset),
            'lastIngestion' => $this->lastIngestion($dataset),
            'graph' => [
                'qdrant' => $this->vectorStats->stats($dataset),
                'neo4j' => $this->graphStats->stats($dataset),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tasks(Dataset $dataset): array
    {
        return $this->datasets->recentTasks($dataset)
            ->map(fn (PipelineTask $task): array => [
                'taskId' => $task->task_id,
                'datasetId' => $task->dataset_id,
                'status' => $task->status,
                'counters' => $task->counters ?? [],
                'startedAt' => $task->started_at?->format(DATE_ATOM),
                'finishedAt' => $task->finished_at?->format(DATE_ATOM),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documents(Dataset $dataset): array
    {
        return $this->datasets->recentDocuments($dataset)
            ->map(fn (Document $document): array => [
                'id' => $document->id,
                'datasetId' => $document->dataset_id,
                'heapId' => $document->dataset_id,
                'corpusId' => $document->corpus_id,
                'collection' => $document->collection,
                'sourceType' => $document->source_type,
                'sourceUrl' => $document->source_url,
                'originalFilename' => $document->original_filename,
                'storagePath' => $document->storage_path,
                'checksumSha256' => $document->checksum_sha256,
                'title' => $document->title,
                'status' => $document->status,
                'createdAt' => $document->created_at?->format(DATE_ATOM),
                'updatedAt' => $document->updated_at?->format(DATE_ATOM),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ingestionHistory(Dataset $dataset): array
    {
        return $this->datasets->recentIngestionJobs($dataset)
            ->map(fn (PipelineJob $job): array => [
                'jobId' => $job->job_id,
                'taskId' => $job->task_id,
                'status' => $job->status,
                'sourceUrl' => $job->source_url,
                'localPath' => $job->local_path,
                'errorMessage' => $job->error_message,
                'startedAt' => $job->started_at?->format(DATE_ATOM),
                'finishedAt' => $job->finished_at?->format(DATE_ATOM),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastIngestion(Dataset $dataset): ?array
    {
        $job = $this->datasets->lastTerminalIngestionJob($dataset);

        if (! $job) {
            return null;
        }

        return [
            'jobId' => $job->job_id,
            'taskId' => $job->task_id,
            'status' => $job->status,
            'finishedAt' => $job->finished_at?->format(DATE_ATOM),
        ];
    }
}
