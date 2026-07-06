<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Models\Document;
use App\Models\SpecV2\Heap;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class DocumentSearchPayloadSyncService
{
    public function __construct(
        private DocumentSearchPayloadFactory $payloads,
        private ConfigRepository $config,
        private HttpFactory $http,
    ) {}

    public function syncStoredMetadata(Document $document): void
    {
        $document->loadMissing('heap');

        if (! $document->heap instanceof Heap) {
            return;
        }

        $document->metadata_json = $this->payloads->storedMetadata($document, $document->heap);

        if ($document->isDirty('metadata_json')) {
            $document->save();
        }
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
                    $document->metadata_json = $this->payloads->storedMetadata($document, $heap);

                    if ($document->isDirty('metadata_json')) {
                        $document->save();
                    }

                    $this->syncQdrantPayload($document, $heap, $staleHeapMetadataKeys);
                }
            });
    }

    /**
     * @param list<string> $staleHeapMetadataKeys
     */
    private function syncQdrantPayload(Document $document, Heap $heap, array $staleHeapMetadataKeys): void
    {
        $collection = trim((string) ($heap->qdrant_collection ?: $document->collection));
        if ($collection === '') {
            return;
        }

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

        $keys = array_values(array_unique([
            ...$staleHeapMetadataKeys,
            ...$this->payloads->qdrantHeapPayloadKeys($heap),
        ]));

        if ($keys !== []) {
            $request->post($baseUrl.'/collections/'.rawurlencode($collection).'/points/payload/delete', [
                'keys' => $keys,
                'filter' => $filter,
            ])->throw();
        }

        $request->post($baseUrl.'/collections/'.rawurlencode($collection).'/points/payload', [
            'payload' => $this->payloads->qdrantHeapPayload($document, $heap),
            'filter' => $filter,
        ])->throw();
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
}
