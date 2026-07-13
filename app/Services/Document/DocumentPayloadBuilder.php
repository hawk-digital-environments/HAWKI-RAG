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
            'dataset_id' => $document->dataset_id,
            'source_url' => $document->source_url,
            'content_type' => $document->mime_type,
            'content_hash' => $document->checksum_sha256,
            'qdrant_status' => $this->statuses->qdrantStatus($document, $bridgeResponse),
            'neo4j_status' => $this->statuses->neo4jStatus($document, $metadata, $bridgeResponse),
            'ingested_at' => $document->status === Document::STATUS_COMPLETED
                ? $this->dateValue($document->updated_at ?? $document->created_at)
                : null,
            'metadata' => $metadata,
            'task_id' => $taskId,
            'job_id' => $jobId,
            'title' => $document->title,
            'status' => $document->status,
            'source_type' => $document->source_type,
            'local_path' => $document->storage_path,
            'original_filename' => $document->original_filename,
            'collection' => $document->collection,
            'qdrant_collection' => $this->stringValue($metadata['qdrant_collection'] ?? null) ?? $document->collection,
            'neo4j_namespace' => $this->stringValue($metadata['neo4j_namespace'] ?? null),
            'file_size' => $document->file_size,
            'created_at' => $this->dateValue($document->created_at),
            'updated_at' => $this->dateValue($document->updated_at),
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
            $payload['markdown_preview'] = $preview['content'];
            $payload['markdown_preview_path'] = $preview['path'];
            $payload['markdown_preview_error'] = $preview['error'];
            $payload['markdown_preview_truncated'] = $preview['truncated'];
            $payload['qdrant_point_count'] = $qdrantPointCount;
            $payload['neo4j_entity_count'] = $neo4jEntityCount;
            $payload['neo4j_relation_count'] = $neo4jRelationCount;
            $payload['neo4j_live_stats'] = $liveGraphStats;
            $payload['related_jobs'] = $this->relatedJobs($document, $taskId, $jobId);
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
                'job_id' => $job->job_id,
                'task_id' => $job->task_id,
                'parent_job_id' => $job->parent_job_id,
                'job_type' => $job->job_type,
                'status' => $job->status,
                'source_url' => $job->source_url,
                'local_path' => $job->local_path,
                'content_hash' => $job->content_hash,
                'error_message' => $job->error_message,
                'started_at' => $this->dateValue($job->started_at),
                'finished_at' => $this->dateValue($job->finished_at),
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
