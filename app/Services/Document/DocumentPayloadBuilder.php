<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\Document;
use App\Models\PipelineJob;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class DocumentPayloadBuilder
{
    public function __construct(
        private DocumentRepository $documents,
        private DocumentMarkdownPreviewReader $previews,
        private DocumentIngestionStatusResolver $statuses,
        private DocumentGraphStatsService $graphStats,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Document $document, bool $includeDetails): array
    {
        $metadata = $this->arrayValue($document->metadata_json);
        $bridgeResponse = $this->arrayValue($metadata['bridge_response'] ?? null);
        $taskId = $this->stringValue($metadata['task_id'] ?? null);
        $jobId = $this->stringValue($metadata['job_id'] ?? null) ?? $this->stringValue($document->external_id);

        $payload = [
            'id' => $document->id,
            'datasetId' => $document->dataset_id,
            'sourceUrl' => $document->source_url,
            'contentType' => $document->mime_type,
            'contentHash' => $document->checksum_sha256,
            'qdrantStatus' => $this->statuses->qdrantStatus($document, $bridgeResponse),
            'neo4jStatus' => $this->statuses->neo4jStatus($document, $metadata, $bridgeResponse),
            'ingestedAt' => $document->status === Document::STATUS_COMPLETED
                ? $this->dateValue($document->updated_at ?? $document->created_at)
                : null,
            'metadata' => $metadata,
            'taskId' => $taskId,
            'jobId' => $jobId,
            'title' => $document->title,
            'status' => $document->status,
            'sourceType' => $document->source_type,
            'localPath' => $document->storage_path,
            'originalFilename' => $document->original_filename,
            'collection' => $document->collection,
            'qdrantCollection' => $this->stringValue($metadata['qdrant_collection'] ?? null) ?? $document->collection,
            'neo4jNamespace' => $this->stringValue($metadata['neo4j_namespace'] ?? null),
            'fileSize' => $document->file_size,
            'createdAt' => $this->dateValue($document->created_at),
            'updatedAt' => $this->dateValue($document->updated_at),
        ];

        if ($includeDetails) {
            $qdrantPointCount = $this->statuses->qdrantPointCount($bridgeResponse);
            $neo4jEntityCount = $this->statuses->neo4jEntityCount($bridgeResponse);
            $neo4jRelationCount = $this->statuses->neo4jRelationCount($bridgeResponse);
            $liveGraphStats = null;

            if ($neo4jEntityCount === null || $neo4jRelationCount === null) {
                $liveGraphStats = $this->graphStats->stats($document, $metadata);
                if (($liveGraphStats['ok'] ?? false) === true) {
                    $neo4jEntityCount ??= (int) ($liveGraphStats['nodes'] ?? 0);
                    $neo4jRelationCount ??= (int) ($liveGraphStats['relationships'] ?? 0);
                }
            }

            $preview = $this->previews->preview($document->storage_path);
            $payload['markdownPreview'] = $preview['content'];
            $payload['markdownPreviewPath'] = $preview['path'];
            $payload['markdownPreviewError'] = $preview['error'];
            $payload['markdownPreviewTruncated'] = $preview['truncated'];
            $payload['qdrantPointCount'] = $qdrantPointCount;
            $payload['neo4jEntityCount'] = $neo4jEntityCount;
            $payload['neo4jRelationCount'] = $neo4jRelationCount;
            $payload['neo4jLiveStats'] = $liveGraphStats;
            $payload['relatedJobs'] = $this->relatedJobs($document, $taskId, $jobId);
        }

        return $payload;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function relatedJobs(Document $document, ?string $taskId, ?string $jobId): array
    {
        return $this->documents->relatedJobs($document, $taskId, $jobId)
            ->map(fn (PipelineJob $job): array => [
                'jobId' => $job->job_id,
                'taskId' => $job->task_id,
                'parentJobId' => $job->parent_job_id,
                'jobType' => $job->job_type,
                'status' => $job->status,
                'sourceUrl' => $job->source_url,
                'localPath' => $job->local_path,
                'contentHash' => $job->content_hash,
                'errorMessage' => $job->error_message,
                'startedAt' => $this->dateValue($job->started_at),
                'finishedAt' => $this->dateValue($job->finished_at),
            ])
            ->all();
    }

    private function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function dateValue(mixed $date): ?string
    {
        return $date && method_exists($date, 'toIso8601String') ? $date->toIso8601String() : null;
    }
}
