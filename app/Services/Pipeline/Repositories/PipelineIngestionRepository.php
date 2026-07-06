<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\Document;
use App\Services\SpecV2\CorpusSyncService;
use App\Services\SpecV2\DocumentSearchPayloadSyncService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineIngestionRepository
{
    public function __construct(
        private CorpusSyncService $corpora,
        private DocumentSearchPayloadSyncService $documents,
    ) {}

    /**
     * @param array{dataset_id:string,qdrant_collection:string,neo4j_namespace:string} $targets
     * @param array<string, mixed> $bridgeResponse
     */
    public function upsertIngestedDocument(
        array $event,
        array $targets,
        string $path,
        string $checksum,
        ?int $fileSize,
        array $bridgeResponse,
    ): Document {
        $document = Document::query()->updateOrCreate(
            [
                'collection' => $targets['qdrant_collection'],
                'checksum_sha256' => $checksum,
            ],
            [
                'external_id' => (string) $event['job_id'],
                'dataset_id' => $targets['dataset_id'],
                'source_type' => Document::SOURCE_SCRAPE,
                'source_url' => $event['source_url'],
                'original_filename' => basename($path),
                'storage_path' => $path,
                'mime_type' => 'text/markdown',
                'file_size' => $fileSize,
                'title' => pathinfo($path, PATHINFO_FILENAME),
                'metadata_json' => [
                    'task_id' => $event['task_id'],
                    'job_id' => $event['job_id'],
                    'event_id' => $event['event_id'],
                    'qdrant_collection' => $targets['qdrant_collection'],
                    'neo4j_namespace' => $targets['neo4j_namespace'],
                    'bridge_response' => $bridgeResponse,
                ],
                'status' => Document::STATUS_COMPLETED,
            ],
        );

        $this->corpora->syncFromDocument($document);
        $this->documents->syncStoredMetadata($document);

        return $document;
    }
}
