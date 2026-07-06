<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use App\Models\Document;
use App\Services\SpecV2\CorpusSyncService;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineIngestionRepository
{
    public function __construct(
        private CorpusSyncService $corpora,
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
        $document = Document::query()
            ->where('collection', $targets['qdrant_collection'])
            ->where('checksum_sha256', $checksum)
            ->first() ?? new Document();

        $metadata = $this->mergedMetadata($document, $event, $targets, $bridgeResponse);

        $document->fill([
            'external_id' => (string) $event['job_id'],
            'dataset_id' => $targets['dataset_id'],
            'collection' => $targets['qdrant_collection'],
            'checksum_sha256' => $checksum,
            'source_type' => $this->sourceType($document),
            'source_url' => $this->stringValue($document->source_url) ?? (string) $event['source_url'],
            'original_filename' => $this->stringValue($document->original_filename) ?? basename($path),
            'storage_path' => $path,
            'mime_type' => 'text/markdown',
            'file_size' => $fileSize,
            'title' => $this->stringValue($document->title) ?? pathinfo($path, PATHINFO_FILENAME),
            'metadata_json' => $metadata,
            'status' => Document::STATUS_COMPLETED,
        ]);
        $document->save();

        $this->corpora->syncFromDocument($document);

        return $document;
    }

    /**
     * @param array<string, mixed> $event
     * @param array{dataset_id:string,qdrant_collection:string,neo4j_namespace:string} $targets
     * @param array<string, mixed> $bridgeResponse
     * @return array<string, mixed>
     */
    private function mergedMetadata(Document $document, array $event, array $targets, array $bridgeResponse): array
    {
        $metadata = is_array($document->metadata_json) ? $document->metadata_json : [];
        unset($metadata['__rawki']);

        return array_replace($metadata, [
            'task_id' => $event['task_id'],
            'job_id' => $event['job_id'],
            'event_id' => $event['event_id'],
            'qdrant_collection' => $targets['qdrant_collection'],
            'neo4j_namespace' => $targets['neo4j_namespace'],
            'bridge_response' => $bridgeResponse,
        ]);
    }

    private function sourceType(Document $document): string
    {
        return $this->stringValue($document->source_type) ?? Document::SOURCE_SCRAPE;
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
