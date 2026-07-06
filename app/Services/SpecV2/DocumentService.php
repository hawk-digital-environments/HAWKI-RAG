<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Models\Document;
use App\Models\SpecV2\Heap;
use App\Services\Authorization\ApiActor;
use App\Services\Authorization\ApiActorScopeService;
use App\Services\Document\DocumentRepository;
use App\Services\OpenCompat\OpenCompatBridgeClient;
use App\Services\OpenCompat\OpenCompatIngestService;
use App\Services\SpecV2\Exceptions\AuthorizationGrantException;
use App\Services\SpecV2\Exceptions\HeapNotFoundException;
use App\Services\SpecV2\Repositories\CorpusRepository;
use App\Services\SpecV2\Repositories\HeapRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

#[Singleton]
readonly class DocumentService
{
    public function __construct(
        private HeapRepository $heaps,
        private DocumentRepository $documents,
        private CorpusRepository $corpora,
        private CorpusSyncService $corpusSync,
        private ApiActorScopeService $actors,
        private OpenCompatIngestService $ingest,
        private OpenCompatBridgeClient $bridge,
        private SpecIdentifierFactory $identifiers,
        private Filesystem $files,
    ) {}

    public function listForHeap(string $heapId, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $heap = $this->requireReadableHeap($heapId);

        return $this->documents->paginate([
            ...$filters,
            'heap_id' => $heap->dataset_id,
        ], $perPage, $page);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{document?: Document, is_duplicate?: bool, payload?: array<string, mixed>, status: int}
     */
    public function create(string $heapId, array $input, ?string $idempotencyKey = null): array
    {
        $heap = $this->requireOwnedHeap($heapId);
        $documentId = $this->identifiers->stringValue($input['document_id'] ?? $input['id'] ?? null) ?? (string) Str::uuid();
        $content = (string) $input['content'];
        $metadata = $this->metadata($input['metadata'] ?? []);
        $checksum = hash('sha256', $content);
        $isDuplicate = $this->corpora->exists($checksum);
        $collection = $this->collection($heap);

        $bridge = $this->ingest->ingestText([
            'text' => $content,
            'document_id' => $documentId,
            'metadata' => $metadata,
            'collection' => $collection,
            'graph' => false,
        ], $idempotencyKey);

        if ($bridge['status'] >= 400) {
            return $bridge;
        }

        $storagePath = $this->writeDocumentContent($heap->dataset_id, $documentId, $content);
        $document = $this->documents->create([
            'id' => $documentId,
            'external_id' => $documentId,
            'dataset_id' => $heap->dataset_id,
            'collection' => $collection,
            'source_type' => Document::SOURCE_API,
            'source_url' => $this->identifiers->stringValue($input['source_url'] ?? null) ?? 'spec://'.$documentId,
            'original_filename' => $this->identifiers->stringValue($input['filename'] ?? null) ?? $documentId.'.txt',
            'storage_path' => $storagePath,
            'mime_type' => 'text/plain',
            'file_size' => strlen($content),
            'checksum_sha256' => $checksum,
            'title' => $this->identifiers->stringValue($input['title'] ?? null),
            'metadata_json' => $metadata,
            'status' => Document::STATUS_COMPLETED,
        ]);

        $this->corpusSync->syncFromDocument($document);

        return [
            'status' => 201,
            'document' => $document->fresh(),
            'is_duplicate' => $isDuplicate,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{document?: Document, payload?: array<string, mixed>, status: int}
     */
    public function update(string $documentId, array $input, ?string $idempotencyKey = null): array
    {
        $document = $this->requireOwnedDocument($documentId);
        $oldChecksum = $this->identifiers->stringValue($document->checksum_sha256);

        $content = $this->identifiers->stringValue($input['content'] ?? null);
        if ($content !== null) {
            $bridge = $this->bridge->put('/documents/'.rawurlencode($documentId), [
                'text' => $content,
                'payload' => $this->metadata($input['metadata'] ?? $document->metadata_json ?? []),
                'collection' => $document->collection,
                'graph' => false,
            ], $idempotencyKey);

            if ($bridge['status'] >= 400) {
                return $bridge;
            }

            $storagePath = $this->writeDocumentContent($document->dataset_id, (string) $document->id, $content);
            $document->storage_path = $storagePath;
            $document->mime_type = 'text/plain';
            $document->file_size = strlen($content);
            $document->checksum_sha256 = hash('sha256', $content);
            $document->corpus_id = null;
        }

        if (array_key_exists('metadata', $input)) {
            $document->metadata_json = $this->metadata($input['metadata']);
        }

        if (array_key_exists('source_url', $input)) {
            $document->source_url = $this->identifiers->stringValue($input['source_url']);
        }

        if (array_key_exists('title', $input)) {
            $document->title = $this->identifiers->stringValue($input['title']);
        }

        if (array_key_exists('filename', $input)) {
            $document->original_filename = $this->identifiers->stringValue($input['filename']);
        }

        $this->documents->save($document);

        if ($content !== null) {
            $this->corpusSync->syncFromDocument($document);
            if ($oldChecksum !== null && $oldChecksum !== $document->checksum_sha256) {
                $this->corpora->refreshReferenceCount($oldChecksum);
            }
        }

        return [
            'status' => 200,
            'document' => $document->fresh(),
        ];
    }

    /**
     * @return array{payload?: array<string, mixed>, status: int}
     */
    public function delete(string $documentId, ?string $idempotencyKey = null): array
    {
        $document = $this->requireOwnedDocument($documentId);
        $bridge = $this->bridge->delete('/documents/'.rawurlencode($documentId), $idempotencyKey);

        if ($bridge['status'] >= 500) {
            return $bridge;
        }

        $oldChecksum = $this->identifiers->stringValue($document->checksum_sha256);
        $storagePath = $this->identifiers->stringValue($document->storage_path);
        $this->documents->delete($document);

        if ($oldChecksum !== null) {
            $this->corpora->refreshReferenceCount($oldChecksum);
        }

        if ($storagePath !== null && $this->files->exists($storagePath)) {
            $this->files->delete($storagePath);
        }

        return ['status' => 204];
    }

    private function requireReadableHeap(string $heapId): Heap
    {
        $heap = $this->heaps->findById($heapId);
        if (! $heap instanceof Heap || ! $this->currentCanReadHeap($heap)) {
            throw HeapNotFoundException::withId($heapId);
        }

        return $heap;
    }

    private function requireOwnedHeap(string $heapId): Heap
    {
        $heap = $this->heaps->findById($heapId);
        if (! $heap instanceof Heap || ! $this->currentOwnsHeap($heap)) {
            throw HeapNotFoundException::withId($heapId);
        }

        return $heap;
    }

    private function requireOwnedDocument(string $documentId): Document
    {
        $document = $this->documents->findById($documentId);
        if (! $document instanceof Document) {
            throw AuthorizationGrantException::documentNotFound($documentId);
        }

        $document->loadMissing('heap');

        if (! $document->heap instanceof Heap || ! $this->currentOwnsHeap($document->heap)) {
            throw AuthorizationGrantException::documentNotFound($documentId);
        }

        return $document;
    }

    private function currentOwnsHeap(Heap $heap): bool
    {
        $actor = $this->actors->currentActor();

        return $actor instanceof ApiActor && $actor->applicationId() === (string) $heap->owner_application_id;
    }

    private function currentCanReadHeap(Heap $heap): bool
    {
        $actor = $this->actors->currentActor();
        if (! $actor instanceof ApiActor) {
            return false;
        }

        if ($actor->hasApplicationPermission(\App\Models\SpecV2\Application::PERMISSION_READS_FEDERATED)) {
            return true;
        }

        if ($actor->hasApplicationPermission(\App\Models\SpecV2\Application::PERMISSION_READS_ALL_APPS)) {
            return (string) $heap->tenant_id === $actor->tenantId();
        }

        return (string) $heap->owner_application_id === $actor->applicationId();
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(mixed $metadata): array
    {
        return is_array($metadata) ? $metadata : [];
    }

    private function collection(Heap $heap): string
    {
        return trim((string) ($heap->qdrant_collection ?: $heap->dataset_id));
    }

    private function writeDocumentContent(string $heapId, string $documentId, string $content): string
    {
        $directory = storage_path('app/rawki/v2_documents/'.$heapId);
        $this->files->ensureDirectoryExists($directory);

        $path = $directory.DIRECTORY_SEPARATOR.$documentId.'.txt';
        $this->files->put($path, $content);

        return $path;
    }
}
