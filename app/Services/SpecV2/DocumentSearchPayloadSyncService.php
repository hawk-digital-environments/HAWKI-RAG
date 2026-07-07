<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Models\Document;
use App\Models\SpecV2\Heap;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

#[Singleton]
readonly class DocumentSearchPayloadSyncService
{
    public function __construct(
        private DocumentSearchPayloadFactory $payloads,
        private ConfigRepository $config,
        private HttpFactory $http,
    ) {}

    /**
     * @param list<string> $stalePayloadKeys
     */
    public function syncDocument(Document $document, array $stalePayloadKeys = [], ?string $previousCollection = null): void
    {
        if ($document->relationLoaded('heap')) {
            $heap = $document->getRelation('heap');
            if (! $heap instanceof Heap || $heap->heapId() !== $document->heapId()) {
                $document->unsetRelation('heap');
            }
        }

        $document->loadMissing('heap');

        if (! $document->heap instanceof Heap) {
            return;
        }

        $payload = $this->payloads->build($document, $document->heap);

        $this->syncQdrantPayload(
            $document,
            $document->heap,
            $payload->qdrantPayload,
            array_values(array_unique([
                ...$stalePayloadKeys,
                ...$payload->payloadKeys,
            ])),
            $previousCollection,
        );
    }

    /**
     * @param list<string> $staleHeapMetadataKeys
     */
    public function propagateHeap(Heap $heap, array $staleHeapMetadataKeys = []): void
    {
        $staleHeapMetadataKeys = $this->normalizedKeys($staleHeapMetadataKeys);

        $heap->documents()
            ->orderBy('id')
            ->chunk(100, function ($documents) use ($heap, $staleHeapMetadataKeys): void {
                foreach ($documents as $document) {
                    if (! $document instanceof Document) {
                        continue;
                    }

                    $document->setRelation('heap', $heap);
                    $this->syncDocument($document, $staleHeapMetadataKeys);
                }
            });
    }

    /**
     * @param list<string> $staleHeapMetadataKeys
     * @param array<string, mixed> $payload
     */
    private function syncQdrantPayload(
        Document $document,
        Heap $heap,
        array $payload,
        array $staleHeapMetadataKeys,
        ?string $previousCollection = null,
    ): void
    {
        $baseUrl = rtrim((string) $this->config->get('config.qdrant_http_url', 'http://qdrant:6333'), '/');
        if ($baseUrl === '') {
            return;
        }

        $request = $this->http->timeout(10)->acceptJson();
        $apiKey = $this->config->get('model_providers.vector_stores.qdrant.api_key');
        if (is_string($apiKey) && trim($apiKey) !== '') {
            $request = $request->withHeader('api-key', $apiKey);
        }

        $filter = [
            'must' => [[
                'key' => 'doc_id',
                'match' => ['value' => (string) $document->id],
            ]],
        ];

        $currentCollection = trim((string) ($heap->qdrant_collection ?: $document->collection));
        $collections = array_values(array_unique(array_filter([
            $this->stringValue($previousCollection),
            $this->stringValue($currentCollection),
        ])));

        if ($collections === []) {
            return;
        }

        $keys = $this->normalizedKeys($staleHeapMetadataKeys);

        foreach ($collections as $collection) {
            try {
                if ($keys !== []) {
                    $request->post($baseUrl.'/collections/'.rawurlencode($collection).'/points/payload/delete', [
                        'keys' => $keys,
                        'filter' => $filter,
                    ])->throw();
                }

                if ($collection === $currentCollection) {
                    $request->post($baseUrl.'/collections/'.rawurlencode($collection).'/points/payload', [
                        'payload' => $payload,
                        'filter' => $filter,
                    ])->throw();
                }
            } catch (\Throwable $exception) {
                Log::warning('Document search payload sync to Qdrant failed.', [
                    'document_id' => (string) $document->id,
                    'collection' => $collection,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private function normalizedKeys(array $keys): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (string $key): string => trim($key),
            $keys,
        ))));
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
