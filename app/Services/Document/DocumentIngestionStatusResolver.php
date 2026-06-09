<?php

declare(strict_types=1);

namespace App\Services\Document;

use App\Models\Document;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class DocumentIngestionStatusResolver
{
    public function qdrantStatus(Document $document, array $bridgeResponse): string
    {
        if (($bridgeResponse['ok'] ?? null) === true || $document->status === Document::STATUS_COMPLETED) {
            return 'indexed';
        }

        if ($document->status === Document::STATUS_FAILED) {
            return 'failed';
        }

        return $document->status ?: 'unknown';
    }

    public function neo4jStatus(Document $document, array $metadata, array $bridgeResponse): string
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

    public function qdrantPointCount(array $bridgeResponse): ?int
    {
        return $this->firstInt([
            $bridgeResponse['points'] ?? null,
            $this->valueAt($bridgeResponse, 'summary.planned_points'),
            $this->valueAt($bridgeResponse, 'summary.qdrant_preview.planned_points'),
            $this->valueAt($bridgeResponse, 'qdrant.points'),
            $this->valueAt($bridgeResponse, 'qdrant.point_count'),
        ]);
    }

    public function neo4jEntityCount(array $bridgeResponse): ?int
    {
        return $this->firstInt([
            $this->valueAt($bridgeResponse, 'summary.graph_preview.planned_entities'),
            $this->valueAt($bridgeResponse, 'summary.graph_preview.total_entities'),
            $this->valueAt($bridgeResponse, 'summary.graph_preview.entities'),
            $this->valueAt($bridgeResponse, 'neo4j.entities'),
            $this->valueAt($bridgeResponse, 'neo4j.entity_count'),
        ]);
    }

    public function neo4jRelationCount(array $bridgeResponse): ?int
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
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
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
}
