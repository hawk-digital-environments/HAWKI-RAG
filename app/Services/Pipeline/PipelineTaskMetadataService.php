<?php
declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Models\Dataset;
use App\Models\PipelineTask;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineTaskMetadataService
{
    /**
     * @return array<string, string>
     */
    public function dataset(Dataset $dataset): array
    {
        return [
            'dataset_id' => $dataset->dataset_id,
            'qdrant_collection' => $dataset->qdrant_collection,
            'neo4j_namespace' => $dataset->neo4j_namespace,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function taskJob(PipelineTask $task): array
    {
        $metadata = $task->metadata ?? [];
        $request = is_array($metadata['request'] ?? null) ? $metadata['request'] : [];
        $requestMetadata = is_array($request['metadata'] ?? null) ? $request['metadata'] : [];

        return array_merge($requestMetadata, [
            'dataset' => is_array($metadata['dataset'] ?? null) ? $metadata['dataset'] : [],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function appendEvent(PipelineTask $task, string $event): array
    {
        $metadata = $task->metadata ?? [];
        $events = is_array($metadata['events'] ?? null) ? $metadata['events'] : [];
        $events[] = [
            'event' => $event,
            'at' => now()->toIso8601String(),
        ];
        $metadata['events'] = $events;

        return $metadata;
    }
}
