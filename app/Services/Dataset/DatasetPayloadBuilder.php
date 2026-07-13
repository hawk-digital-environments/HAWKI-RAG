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
            'dataset_id' => $dataset->dataset_id,
            'name' => $dataset->name,
            'description' => $dataset->description,
            'status' => $dataset->status,
            'qdrant_collection' => $dataset->qdrant_collection,
            'neo4j_namespace' => $dataset->neo4j_namespace,
            'created_at' => $dataset->created_at?->format(DATE_ATOM),
            'document_count' => $stats['documents'],
            'task_count' => $stats['tasks'],
            'last_ingestion' => $stats['last_ingestion'],
            'graph_stats' => $stats['graph'],
        ];

        if ($includeDetails) {
            $payload['tasks'] = $this->tasks($dataset);
            $payload['documents'] = $this->documents($dataset);
            $payload['ingestion_history'] = $this->ingestionHistory($dataset);
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
            'last_ingestion' => $this->lastIngestion($dataset),
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
                'task_id' => $task->task_id,
                'dataset_id' => $task->dataset_id,
                'status' => $task->status,
                'counters' => $task->counters ?? [],
                'started_at' => $task->started_at?->format(DATE_ATOM),
                'finished_at' => $task->finished_at?->format(DATE_ATOM),
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
                'dataset_id' => $document->dataset_id,
                'collection' => $document->collection,
                'source_type' => $document->source_type,
                'source_url' => $document->source_url,
                'original_filename' => $document->original_filename,
                'storage_path' => $document->storage_path,
                'checksum_sha256' => $document->checksum_sha256,
                'title' => $document->title,
                'status' => $document->status,
                'created_at' => $document->created_at?->format(DATE_ATOM),
                'updated_at' => $document->updated_at?->format(DATE_ATOM),
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
                'job_id' => $job->job_id,
                'task_id' => $job->task_id,
                'status' => $job->status,
                'source_url' => $job->source_url,
                'local_path' => $job->local_path,
                'error_message' => $job->error_message,
                'started_at' => $job->started_at?->format(DATE_ATOM),
                'finished_at' => $job->finished_at?->format(DATE_ATOM),
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
            'job_id' => $job->job_id,
            'task_id' => $job->task_id,
            'status' => $job->status,
            'finished_at' => $job->finished_at?->format(DATE_ATOM),
        ];
    }
}
