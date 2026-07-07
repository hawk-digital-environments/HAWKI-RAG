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
use App\Services\Pipeline\Uploads\PipelineUploadService;
use App\Services\Pipeline\Values\PipelineUploadInput;
use App\Services\Settings\SettingsService;
use App\Services\SpecV2\Exceptions\AccessDeniedException;
use App\Services\SpecV2\Exceptions\AuthorizationGrantException;
use App\Services\SpecV2\Exceptions\HeapNotFoundException;
use App\Services\SpecV2\Repositories\CorpusRepository;
use App\Services\SpecV2\Repositories\HeapRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
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
        private PipelineUploadService $uploads,
        private SettingsService $settings,
        private SpecIdentifierFactory $identifiers,
        private Filesystem $files,
    ) {}

    public function listForHeap(string $heapId, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $heap = $this->requireReadableHeap($heapId);

        return $this->documents->paginate([
            ...$filters,
            'heap_id' => $heap->heapId(),
        ], $perPage, $page);
    }

    public function show(string $documentId): Document
    {
        $document = $this->documents->findById($documentId);
        if (! $document instanceof Document) {
            throw AuthorizationGrantException::documentNotFound($documentId);
        }

        $document->loadMissing('heap');
        if (! $document->heap instanceof Heap || ! $this->actors->currentCanReadHeap($document->heap)) {
            throw AuthorizationGrantException::documentNotFound($documentId);
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{document?: Document, is_duplicate?: bool, job_id?: string, payload?: array<string, mixed>, source_id?: string, status: int, task_id?: string}
     */
    public function create(
        string $heapId,
        array $input,
        ?UploadedFile $file = null,
        ?string $idempotencyKey = null,
    ): array
    {
        $heap = $this->requireOwnedHeap($heapId);
        if ($file instanceof UploadedFile) {
            return $this->createUploadedDocument($heap, $input, $file);
        }

        return $this->createTextDocument($heap, $input, $idempotencyKey);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{document?: Document, is_duplicate?: bool, payload?: array<string, mixed>, status: int}
     */
    private function createTextDocument(Heap $heap, array $input, ?string $idempotencyKey = null): array
    {
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

        $storagePath = $this->writeDocumentContent($heap->heapId(), $documentId, $content);
        $document = $this->documents->create([
            'id' => $documentId,
            'external_id' => $documentId,
            'heap_id' => $heap->heapId(),
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
     * @return array{document?: Document, is_duplicate?: bool, job_id?: string, payload?: array<string, mixed>, source_id?: string, status: int, task_id?: string}
     */
    private function createUploadedDocument(Heap $heap, array $input, UploadedFile $file): array
    {
        $documentId = $this->identifiers->stringValue($input['document_id'] ?? $input['id'] ?? null) ?? (string) Str::uuid();
        $metadata = $this->metadata($input['metadata'] ?? []);
        $collection = $this->collection($heap);
        $originalFilename = $this->identifiers->stringValue($input['filename'] ?? null) ?? ($file->getClientOriginalName() ?: $documentId);
        $mimeType = $this->identifiers->stringValue($file->getClientMimeType()) ?? 'application/octet-stream';
        $fileSize = $file->getSize();
        $title = $this->identifiers->stringValue($input['title'] ?? null) ?? pathinfo($originalFilename, PATHINFO_FILENAME);

        $upload = $this->uploads->upload(
            PipelineUploadInput::fromValidated([
                'heap_id' => $heap->heapId(),
                'graph' => false,
            ], $this->settings->customConverterUploadDefaults()),
            $file,
        );

        if ($upload->status >= 400) {
            return [
                'status' => $upload->status,
                'payload' => $upload->payload,
            ];
        }

        $checksum = $this->identifiers->stringValue($upload->payload['contentHash'] ?? null);
        if ($checksum === null) {
            return [
                'status' => 500,
                'payload' => ['message' => 'Upload pipeline did not return a document checksum.'],
            ];
        }

        $document = $this->documents->create([
            'id' => $documentId,
            'external_id' => $documentId,
            'heap_id' => $heap->heapId(),
            'collection' => $collection,
            'source_type' => Document::SOURCE_UPLOAD,
            'source_url' => $this->identifiers->stringValue($input['source_url'] ?? null)
                ?? $this->identifiers->stringValue($upload->payload['sourceUrl'] ?? null)
                ?? 'upload://'.$documentId,
            'original_filename' => $originalFilename,
            'storage_path' => $this->identifiers->stringValue($upload->payload['localPath'] ?? null),
            'mime_type' => $mimeType,
            'file_size' => is_int($fileSize) ? $fileSize : null,
            'checksum_sha256' => $checksum,
            'title' => $title,
            'metadata_json' => $this->queuedUploadMetadata($metadata, $upload->payload),
            'status' => Document::STATUS_QUEUED,
        ]);

        return [
            'status' => 201,
            'document' => $document->fresh(),
            'is_duplicate' => $this->corpora->exists($checksum),
            'task_id' => $this->identifiers->stringValue($upload->payload['taskId'] ?? null),
            'job_id' => $this->identifiers->stringValue($upload->payload['jobId'] ?? null),
            'source_id' => $this->identifiers->stringValue($upload->payload['sourceId'] ?? null),
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
        $targetHeap = $this->targetHeap($input);

        $content = $this->identifiers->stringValue($input['content'] ?? null);
        if ($content !== null) {
            $bridge = $this->bridge->put('/documents/'.rawurlencode($documentId), [
                'text' => $content,
                'payload' => $this->metadata($input['metadata'] ?? $document->metadata_json ?? []),
                'collection' => $targetHeap instanceof Heap ? $this->collection($targetHeap) : $document->collection,
                'graph' => false,
            ], $idempotencyKey);

            if ($bridge['status'] >= 400) {
                return $bridge;
            }

            $storagePath = $this->writeDocumentContent(
                $targetHeap instanceof Heap ? $targetHeap->heapId() : (string) $document->heapId(),
                (string) $document->id,
                $content,
            );
            $document->storage_path = $storagePath;
            $document->mime_type = 'text/plain';
            $document->file_size = strlen($content);
            $document->checksum_sha256 = hash('sha256', $content);
            $document->corpus_id = null;
        }

        if ($targetHeap instanceof Heap && $targetHeap->heapId() !== $document->heapId()) {
            $document->moveToHeap($targetHeap);
            $document->collection = $this->collection($targetHeap);
            $document->unsetRelation('heap');
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
     * @param array<string, mixed> $input
     */
    private function targetHeap(array $input): ?Heap
    {
        if (! array_key_exists('heap_id', $input)) {
            return null;
        }

        $heapId = $this->identifiers->stringValue($input['heap_id'] ?? null);

        return $heapId === null ? null : $this->requireOwnedHeap($heapId);
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
        if (! $heap instanceof Heap) {
            throw HeapNotFoundException::withId($heapId);
        }

        if (! $this->actors->currentCanReadHeap($heap)) {
            throw AccessDeniedException::forAction('read', 'heap', $heapId);
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

    /**
     * @return array<string, mixed>
     */
    private function metadata(mixed $metadata): array
    {
        return is_array($metadata) ? $metadata : [];
    }

    private function collection(Heap $heap): string
    {
        return trim((string) ($heap->qdrant_collection ?: $heap->heapId()));
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function queuedUploadMetadata(array $metadata, array $payload): array
    {
        $uploadMetadata = array_filter([
            'local_path' => $this->identifiers->stringValue($payload['localPath'] ?? null),
            'source_id' => $this->identifiers->stringValue($payload['sourceId'] ?? null),
            'stored_filename' => $this->identifiers->stringValue($payload['storedFilename'] ?? null),
            'original_filename' => $this->identifiers->stringValue($payload['originalFilename'] ?? null),
            'content_hash' => $this->identifiers->stringValue($payload['contentHash'] ?? null),
            'extension' => $this->identifiers->stringValue($payload['extension'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        return array_replace($metadata, array_filter([
            'task_id' => $this->identifiers->stringValue($payload['taskId'] ?? null),
            'job_id' => $this->identifiers->stringValue($payload['jobId'] ?? null),
            'upload' => $uploadMetadata === [] ? null : $uploadMetadata,
        ], static fn (mixed $value): bool => $value !== null));
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
