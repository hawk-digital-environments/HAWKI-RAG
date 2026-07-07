<?php

declare(strict_types=1);

namespace App\Services\Heap;

use App\Models\Dataset;
use App\Models\Document;
use App\Models\PipelineJob;
use App\Models\PipelineTask;
use App\Services\Heap\Repositories\HeapActivityRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class HeapPayloadBuilder
{
    public function __construct(
        private HeapActivityRepository $heaps,
        private HeapVectorStatsService $vectorStats,
        private HeapGraphStatsService $graphStats,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Dataset $heap, bool $includeDetails): array
    {
        $stats = $this->stats($heap);
        $payload = [
            'id' => $heap->id,
            'heapId' => $heap->heapId(),
            'name' => $heap->name,
            'description' => $heap->description,
            'status' => $heap->status,
            'tenantId' => $heap->tenant_id,
            'ownerApp' => $heap->owner_application_id,
            'visibility' => $heap->visibility,
            'protected' => (bool) $heap->protected,
            'metadata' => $heap->metadata_json ?? [],
            'qdrantCollection' => $heap->qdrant_collection,
            'neo4jNamespace' => $heap->neo4j_namespace,
            'createdAt' => $heap->created_at?->format(DATE_ATOM),
            'updatedAt' => $heap->updated_at?->format(DATE_ATOM),
            'documentCount' => $stats['documents'],
            'taskCount' => $stats['tasks'],
            'lastIngestion' => $stats['lastIngestion'],
            'graphStats' => $stats['graph'],
        ];

        if ($includeDetails) {
            $payload['tasks'] = $this->tasks($heap);
            $payload['documents'] = $this->documents($heap);
            $payload['ingestionHistory'] = $this->ingestionHistory($heap);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(Dataset $heap): array
    {
        return [
            'documents' => $this->heaps->documentCount($heap),
            'tasks' => $this->heaps->taskCount($heap),
            'lastIngestion' => $this->lastIngestion($heap),
            'graph' => [
                'qdrant' => $this->vectorStats->stats($heap),
                'neo4j' => $this->graphStats->stats($heap),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tasks(Dataset $heap): array
    {
        return $this->heaps->recentTasks($heap)
            ->map(fn (PipelineTask $task): array => [
                'taskId' => $task->task_id,
                'heapId' => $task->dataset_id,
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
    private function documents(Dataset $heap): array
    {
        return $this->heaps->recentDocuments($heap)
            ->map(fn (Document $document): array => [
                'id' => $document->id,
                'heapId' => $document->heapId(),
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
    private function ingestionHistory(Dataset $heap): array
    {
        return $this->heaps->recentIngestionJobs($heap)
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
    private function lastIngestion(Dataset $heap): ?array
    {
        $job = $this->heaps->lastTerminalIngestionJob($heap);

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
