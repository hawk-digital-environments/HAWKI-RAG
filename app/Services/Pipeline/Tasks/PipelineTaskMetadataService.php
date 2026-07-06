<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Tasks;

use App\Models\Dataset;
use App\Models\PipelineTask;
use Illuminate\Container\Attributes\Singleton;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class PipelineTaskMetadataService
{
    public function __construct(
        private ClockInterface $clock = new Clock,
    ) {}

    /**
     * @return array<string, string>
     */
    public function heap(Dataset $heap): array
    {
        return [
            'heap_id' => $heap->dataset_id,
            'qdrant_collection' => $heap->qdrant_collection,
            'neo4j_namespace' => $heap->neo4j_namespace,
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
        $heap = is_array($metadata['heap'] ?? null) ? $metadata['heap'] : [];

        return array_merge($requestMetadata, [
            'heap' => $heap,
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
            'at' => $this->timestamp(),
        ];
        $metadata['events'] = $events;

        return $metadata;
    }

    private function timestamp(): string
    {
        return $this->clock->now()->format(\DateTimeInterface::ATOM);
    }
}
