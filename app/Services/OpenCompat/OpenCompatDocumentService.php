<?php

declare(strict_types=1);

namespace App\Services\OpenCompat;

use App\Models\Document;
use App\Services\Document\DocumentBrowserService;
use App\Services\Document\DocumentRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class OpenCompatDocumentService
{
    public function __construct(
        private DocumentBrowserService $browser,
        private DocumentRepository $documents,
        private OpenCompatBridgeClient $bridge,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function retrieveDocs(array $input): array
    {
        $result = $this->bridge->post('/query', [
            'query' => (string) ($input['query'] ?? ''),
            'top_k' => (int) ($input['top_k'] ?? $input['k'] ?? 5),
            'filters' => is_array($input['filters'] ?? null) ? $input['filters'] : [],
            'generate' => false,
            'fast_mode' => filter_var($input['fast_mode'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'smart_lookup' => filter_var($input['smart_lookup'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'preferred_tags' => $input['preferred_tags'] ?? null,
        ]);
        if ($result['status'] >= 400 || ! is_array($result['payload'])) {
            return ['status' => $result['status'], 'payload' => $this->errorPayload('retrieval_failed', 'Bridge retrieval failed.', $result['payload'])];
        }

        $chunks = $this->chunksFromHits($this->array($result['payload']['hits'] ?? []));
        $ids = [];
        foreach ($chunks as $chunk) {
            $id = $this->string($chunk['document_id'] ?? null);
            if ($id) {
                $ids[$id] = $id;
            }
        }

        $documents = $this->documents->findByExternalIds(array_values($ids))
            ->map(fn (Document $document): array => $this->documentShapeFromModel($document))
            ->values()
            ->all();

        return ['status' => 200, 'payload' => ['documents' => $documents, 'count' => count($documents)]];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function searchDocuments(array $input): array
    {
        $filters = $this->array($input['scope_filters'] ?? []);
        $documents = array_map(
            fn (array $document): array => $this->documentShape($document),
            $this->browser->list((int) ($input['limit'] ?? 100), [
                ...$filters,
                'search' => $input['query'] ?? $input['filename'] ?? $input['q'] ?? null,
            ]),
        );

        return ['status' => 200, 'payload' => ['documents' => $documents, 'count' => count($documents)]];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function batchDocuments(array $input): array
    {
        $ids = array_values(array_filter(array_map('strval', $this->array($input['document_ids'] ?? $input['documentIds'] ?? $input['ids'] ?? []))));
        $indexed = $this->documents->findManyByIds($ids, $this->array($input['scope_filters'] ?? []))
            ->keyBy(fn (Document $document): string => (string) $document->id);
        $documents = [];
        foreach ($ids as $id) {
            $document = $indexed->get($id);
            if ($document instanceof Document) {
                $documents[] = $this->documentShapeFromModel($document);
            }
        }

        return ['status' => 200, 'payload' => ['documents' => $documents, 'count' => count($documents)]];
    }

    /**
     * @param list<array<string, mixed>> $hits
     * @return list<array<string, mixed>>
     */
    private function chunksFromHits(array $hits): array
    {
        $chunks = [];
        foreach ($hits as $index => $hit) {
            if (! is_array($hit)) {
                continue;
            }
            $payload = $this->array($hit['payload'] ?? []);
            $documentId = $this->string($payload['doc_id'] ?? $payload['document_id'] ?? $payload['id'] ?? $hit['id'] ?? null);
            $chunks[] = [
                'id' => $this->string($hit['id'] ?? null) ?? ($documentId ? $documentId.':'.$index : (string) $index),
                'document_id' => $documentId,
                'content' => (string) ($payload['content'] ?? $payload['text'] ?? ''),
                'score' => $hit['score'] ?? null,
                'metadata' => $payload,
            ];
        }

        return $chunks;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private function documentShape(array $document): array
    {
        return [
            'id' => $document['id'] ?? null,
            'external_id' => $document['jobId'] ?? null,
            'filename' => $document['originalFilename'] ?? basename((string) ($document['sourceUrl'] ?? '')),
            'content_type' => $document['contentType'] ?? null,
            'metadata' => [
                'dataset_id' => $document['datasetId'] ?? null,
                'source_url' => $document['sourceUrl'] ?? null,
                'source_type' => $document['sourceType'] ?? null,
                'collection' => $document['collection'] ?? null,
                ...$this->array($document['metadata'] ?? []),
            ],
            'status' => $document['status'] ?? null,
            'created_at' => $document['createdAt'] ?? null,
            'updated_at' => $document['updatedAt'] ?? null,
            'system_metadata' => [
                'task_id' => $document['taskId'] ?? null,
                'job_id' => $document['jobId'] ?? null,
                'qdrant_status' => $document['qdrantStatus'] ?? null,
                'neo4j_status' => $document['neo4jStatus'] ?? null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentShapeFromModel(Document $document): array
    {
        return $this->documentShape($this->browser->show($document->id) ?? [
            'id' => $document->id,
            'originalFilename' => $document->original_filename,
            'sourceUrl' => $document->source_url,
            'contentType' => $document->mime_type,
            'metadata' => $document->metadata_json ?? [],
            'status' => $document->status,
            'createdAt' => $document->created_at?->toIso8601String(),
            'updatedAt' => $document->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function errorPayload(string $error, string $message, mixed $details = null): array
    {
        return array_filter([
            'ok' => false,
            'error' => $error,
            'message' => $message,
            'details' => $details,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
