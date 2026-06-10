<?php

declare(strict_types=1);

namespace App\Services\Pipeline\EventHandlers;

use App\Services\Pipeline\Repositories\PipelineIngestionRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class IngestedDocumentRecorder
{
    public function __construct(
        private PipelineIngestionRepository $ingestion,
        private IngestionBridgeClient $bridge,
        private PipelineEventArtifactReader $artifacts,
    ) {
    }

    /**
     * @param array<string, mixed> $event
     * @param array<string, mixed> $bridgeResponse
     */
    public function record(array $event, string $path, array $bridgeResponse): void
    {
        $targets = $this->bridge->targets((string) ($event['dataset_id'] ?: 'default'));
        $checksum = $this->artifacts->sha256($path, (string) $event['content_hash']);

        $this->ingestion->upsertIngestedDocument(
            $event,
            $targets,
            $path,
            $checksum,
            $this->artifacts->size($path),
            $bridgeResponse,
        );
    }
}
