<?php

declare(strict_types=1);

namespace App\Services\OpenCompat;

use App\Models\Document;
use App\Services\Authorization\ApiActorScopeService;
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
        private ApiActorScopeService $scopes,
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
        $filters = $this->mergeScopeFilters($this->array($input['scope_filters'] ?? []));
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
        $indexed = $this->documents->findManyByIds($ids, $this->mergeScopeFilters($this->array($input['scope_filters'] ?? [])))
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
     * @param array<string, mixed> $filters
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function listDocuments(array $filters, ?string $requestedUserIdentifier = null): array
    {
        $filters = $this->mergeScopeFilters($filters, $requestedUserIdentifier);
        $documents = array_map(
            fn (array $document): array => $this->documentShape($document),
            $this->browser->list((int) ($filters['limit'] ?? 100), $filters, $requestedUserIdentifier),
        );

        return ['status' => 200, 'payload' => ['documents' => $documents, 'count' => count($documents)]];
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function showDocument(string $documentId, ?string $requestedUserIdentifier = null): array
    {
        $document = $this->browser->show($documentId, $requestedUserIdentifier);
        if (! $document) {
            return ['status' => 404, 'payload' => $this->errorPayload('document_not_found', 'Document not found.')];
        }

        return ['status' => 200, 'payload' => ['document' => $this->documentShape($document)]];
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function documentStatus(string $documentId, ?string $requestedUserIdentifier = null): array
    {
        $shown = $this->showDocument($documentId, $requestedUserIdentifier);
        if ($shown['status'] !== 200) {
            return $shown;
        }

        $document = $shown['payload']['document'];

        return [
            'status' => 200,
            'payload' => [
                'document_id' => $document['id'],
                'status' => $document['status'],
                'task_id' => $document['system_metadata']['task_id'] ?? null,
                'job_id' => $document['system_metadata']['job_id'] ?? null,
                'updated_at' => $document['updated_at'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function updateDocumentMetadata(string $documentId, array $input, ?string $requestedUserIdentifier = null): array
    {
        if (! $this->scopes->currentCanReadDocument($documentId, $requestedUserIdentifier)) {
            return ['status' => 404, 'payload' => $this->errorPayload('document_not_found', 'Document not found.')];
        }

        $document = $this->documents->findById($documentId);
        if (! $document) {
            return ['status' => 404, 'payload' => $this->errorPayload('document_not_found', 'Document not found.')];
        }

        $metadata = $document->metadata_json ?? [];
        $document->metadata_json = array_replace_recursive($metadata, $this->array($input['metadata'] ?? $input));
        $document->save();

        return $this->showDocument($documentId, $requestedUserIdentifier);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function updateDocumentText(string $documentId, array $input, ?string $idempotencyKey = null, ?string $requestedUserIdentifier = null): array
    {
        if (! $this->scopes->currentCanReadDocument($documentId, $requestedUserIdentifier)) {
            return ['status' => 404, 'payload' => $this->errorPayload('document_not_found', 'Document not found.')];
        }

        $document = $this->documents->findById($documentId);
        if (! $document) {
            return ['status' => 404, 'payload' => $this->errorPayload('document_not_found', 'Document not found.')];
        }

        $result = $this->bridge->put('/documents/'.rawurlencode($documentId), [
            'text' => (string) $input['text'],
            'payload' => $this->array($input['metadata'] ?? []),
            'collection' => $this->string($input['collection'] ?? null) ?? $document->collection,
            'graph' => filter_var($input['graph'] ?? false, FILTER_VALIDATE_BOOLEAN),
        ], $idempotencyKey);

        return [
            'status' => $result['status'],
            'payload' => [
                'document_id' => $documentId,
                'status' => $result['status'] < 400 ? 'updated' : 'failed',
                'bridge_response' => $result['payload'],
            ],
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function deleteDocument(string $documentId, ?string $idempotencyKey = null, ?string $requestedUserIdentifier = null): array
    {
        if (! $this->scopes->currentCanReadDocument($documentId, $requestedUserIdentifier)) {
            return ['status' => 404, 'payload' => $this->errorPayload('document_not_found', 'Document not found.')];
        }

        $document = $this->documents->findById($documentId);
        if (! $document) {
            return ['status' => 404, 'payload' => $this->errorPayload('document_not_found', 'Document not found.')];
        }

        $bridge = $this->bridge->delete('/documents/'.rawurlencode($documentId), $idempotencyKey);
        if ($bridge['status'] < 500) {
            $document->delete();
        }

        return [
            'status' => $bridge['status'] < 400 ? 200 : $bridge['status'],
            'payload' => [
                'deleted' => $bridge['status'] < 500,
                'document_id' => $documentId,
                'bridge_response' => $bridge['payload'],
            ],
        ];
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function byFilename(string $filename, ?string $requestedUserIdentifier = null): array
    {
        $documents = $this->browser->list(2, ['search' => $filename], $requestedUserIdentifier);
        foreach ($documents as $document) {
            if (($document['originalFilename'] ?? null) === $filename || basename((string) ($document['sourceUrl'] ?? '')) === $filename) {
                return ['status' => 200, 'payload' => ['document' => $this->documentShape($document)]];
            }
        }

        return ['status' => 404, 'payload' => $this->errorPayload('document_not_found', 'Document not found.')];
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function downloadUrl(string $documentId, ?string $requestedUserIdentifier = null): array
    {
        $shown = $this->showDocument($documentId, $requestedUserIdentifier);
        if ($shown['status'] !== 200) {
            return $shown;
        }

        return $this->unsupported(
            'documents/download_url',
            'Legacy document download URLs are not exposed on the V2 branch.',
        );
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function mergeScopeFilters(array $filters, ?string $requestedUserIdentifier = null): array
    {
        $scope = $this->scopes->currentDocumentFilters($requestedUserIdentifier);
        if ($scope === []) {
            return $filters;
        }

        $scopedDocumentIds = is_array($scope['document_ids'] ?? null)
            ? array_values(array_filter(array_map('strval', $scope['document_ids'])))
            : [];

        $requestedDocumentIds = is_array($filters['document_ids'] ?? null)
            ? array_values(array_filter(array_map('strval', $filters['document_ids'])))
            : (is_array($filters['documentIds'] ?? null)
                ? array_values(array_filter(array_map('strval', $filters['documentIds'])))
                : null);

        if (is_array($requestedDocumentIds)) {
            $scope['document_ids'] = array_values(array_intersect($requestedDocumentIds, $scopedDocumentIds));
        }

        return [
            ...$filters,
            ...$scope,
        ];
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
     * @return array{payload: array<string, mixed>, status: int}
     */
    private function unsupported(string $endpoint, string $reason): array
    {
        return [
            'status' => 501,
            'payload' => [
                'ok' => false,
                'error' => 'unsupported',
                'endpoint' => $endpoint,
                'reason' => $reason,
            ],
        ];
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
