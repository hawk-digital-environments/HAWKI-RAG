<?php

declare(strict_types=1);

namespace App\Services\Dataset\Values;

use App\Models\Dataset;

/**
 * A transient dataset model projected from the Python authority.
 *
 * Laravel's compatibility code needs the dataset attributes, but Python owns
 * the row. The projection is never saved: it exists only to feed legacy
 * metadata, task and payload builders.
 */
final class DatasetProjection
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): Dataset
    {
        $dataset = new Dataset;
        $dataset->forceFill([
            'dataset_id' => (string) $payload['dataset_id'],
            'name' => (string) ($payload['name'] ?? ''),
            'description' => $payload['description'] ?? null,
            'status' => (string) ($payload['status'] ?? Dataset::STATUS_ACTIVE),
            'qdrant_collection' => (string) ($payload['qdrant_collection'] ?? ''),
            'neo4j_namespace' => $payload['neo4j_namespace'] ?? null,
            'embedding_provider' => (string) ($payload['embedding_provider'] ?? ''),
            'embedding_model' => (string) ($payload['embedding_model'] ?? ''),
            'created_at' => $payload['created_at'] ?? null,
        ]);
        $dataset->exists = false;

        return $dataset;
    }
}
