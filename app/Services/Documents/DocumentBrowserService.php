<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\PipelineJob;

class DocumentBrowserService
{
    private const PREVIEW_BYTES = 24000;

    public function __construct(
        private readonly DocumentRepository $documents,
    ) {}

    public function list(int $limit = 100, array $filters = []): array
    {
        $limit = max(1, min(250, $limit));

        return $this->documents->list($filters, $limit)
            ->map(fn (Document $document): array => $this->payload($document, includeDetails: false))
            ->all();
    }

    public function show(string $documentId): ?array
    {
        $document = $this->documents->findById($documentId);

        return $document ? $this->payload($document, includeDetails: true) : null;
    }

    private function payload(Document $document, bool $includeDetails): array
    {
        $metadata = is_array($document->metadata_json) ? $document->metadata_json : [];
        $bridgeResponse = $this->arrayValue($metadata['bridge_response'] ?? null);
        $taskId = $this->stringValue($metadata['task_id'] ?? null);
        $jobId = $this->stringValue($metadata['job_id'] ?? null) ?? $this->stringValue($document->external_id);

        $payload = [
            'id' => $document->id,
            'datasetId' => $document->dataset_id,
            'sourceUrl' => $document->source_url,
            'contentType' => $document->mime_type,
            'contentHash' => $document->checksum_sha256,
            'qdrantStatus' => $this->qdrantStatus($document, $bridgeResponse),
            'neo4jStatus' => $this->neo4jStatus($document, $metadata, $bridgeResponse),
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
            $preview = $this->markdownPreview($document->storage_path);
            $payload['markdownPreview'] = $preview['content'];
            $payload['markdownPreviewPath'] = $preview['path'];
            $payload['markdownPreviewError'] = $preview['error'];
            $payload['markdownPreviewTruncated'] = $preview['truncated'];
            $payload['qdrantPointCount'] = $this->qdrantPointCount($bridgeResponse);
            $payload['neo4jEntityCount'] = $this->neo4jEntityCount($bridgeResponse);
            $payload['neo4jRelationCount'] = $this->neo4jRelationCount($bridgeResponse);
            $payload['relatedJobs'] = $this->relatedJobs($document, $taskId, $jobId);
        }

        return $payload;
    }

    private function markdownPreview(?string $path): array
    {
        $path = $this->stringValue($path);
        if (!$path) {
            return [
                'content' => '',
                'path' => null,
                'error' => 'No local Markdown path is recorded for this document.',
                'truncated' => false,
            ];
        }

        foreach ($this->pathCandidates($path) as $candidate) {
            if (!is_file($candidate) || !is_readable($candidate)) {
                continue;
            }

            $content = file_get_contents($candidate);
            if ($content === false) {
                continue;
            }

            $truncated = strlen($content) > self::PREVIEW_BYTES;

            return [
                'content' => $truncated ? substr($content, 0, self::PREVIEW_BYTES) : $content,
                'path' => $candidate,
                'error' => null,
                'truncated' => $truncated,
            ];
        }

        return [
            'content' => '',
            'path' => $path,
            'error' => "Markdown file is not readable from {$path}.",
            'truncated' => false,
        ];
    }

    private function pathCandidates(string $path): array
    {
        if (str_starts_with($path, '/')) {
            return [$path];
        }

        return array_values(array_unique([
            $path,
            storage_path('app/' . ltrim($path, '/')),
            base_path($path),
        ]));
    }

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

    private function qdrantStatus(Document $document, array $bridgeResponse): string
    {
        if (($bridgeResponse['ok'] ?? null) === true || $document->status === Document::STATUS_COMPLETED) {
            return 'indexed';
        }

        if ($document->status === Document::STATUS_FAILED) {
            return 'failed';
        }

        return $document->status ?: 'unknown';
    }

    private function neo4jStatus(Document $document, array $metadata, array $bridgeResponse): string
    {
        $graphEnabled = $this->valueAt($bridgeResponse, 'summary.graph.enabled')
            ?? $this->valueAt($bridgeResponse, 'graph.enabled')
            ?? ($metadata['graph'] ?? null);

        if ($graphEnabled === false) {
            return 'disabled';
        }

        if ($graphEnabled === true && $document->status === Document::STATUS_COMPLETED) {
            return 'indexed';
        }

        if ($document->status === Document::STATUS_FAILED) {
            return 'failed';
        }

        return $graphEnabled === null ? 'unknown' : (string) $document->status;
    }

    private function qdrantPointCount(array $bridgeResponse): ?int
    {
        return $this->firstInt([
            $bridgeResponse['points'] ?? null,
            $this->valueAt($bridgeResponse, 'summary.planned_points'),
            $this->valueAt($bridgeResponse, 'summary.qdrant_preview.planned_points'),
            $this->valueAt($bridgeResponse, 'qdrant.points'),
            $this->valueAt($bridgeResponse, 'qdrant.point_count'),
        ]);
    }

    private function neo4jEntityCount(array $bridgeResponse): ?int
    {
        return $this->firstInt([
            $this->valueAt($bridgeResponse, 'summary.graph_preview.planned_entities'),
            $this->valueAt($bridgeResponse, 'summary.graph_preview.total_entities'),
            $this->valueAt($bridgeResponse, 'summary.graph_preview.entities'),
            $this->valueAt($bridgeResponse, 'neo4j.entities'),
            $this->valueAt($bridgeResponse, 'neo4j.entity_count'),
        ]);
    }

    private function neo4jRelationCount(array $bridgeResponse): ?int
    {
        return $this->firstInt([
            $this->valueAt($bridgeResponse, 'summary.graph_preview.planned_triplets'),
            $this->valueAt($bridgeResponse, 'summary.graph_preview.total_triplets'),
            $this->valueAt($bridgeResponse, 'summary.graph_preview.relationships'),
            $this->valueAt($bridgeResponse, 'summary.graph_preview.relations'),
            $this->valueAt($bridgeResponse, 'neo4j.relationships'),
            $this->valueAt($bridgeResponse, 'neo4j.relations'),
            $this->valueAt($bridgeResponse, 'neo4j.relationship_count'),
        ]);
    }

    private function valueAt(array $payload, string $path): mixed
    {
        $value = $payload;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function firstInt(array $values): ?int
    {
        foreach ($values as $value) {
            if (is_int($value)) {
                return $value;
            }

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
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
