<?php

declare(strict_types=1);

namespace App\Services\Heap;

use App\Models\Dataset;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class HeapVectorStatsService
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(Dataset $heap): array
    {
        $baseUrl = rtrim((string) $this->config->get('config.qdrant_http_url', 'http://qdrant:6333'), '/');

        try {
            $response = $this->http->timeout(3)->post($baseUrl.'/collections/'.rawurlencode($heap->qdrant_collection).'/points/count', [
                'exact' => true,
            ]);

            if ($response->status() === 404) {
                return [
                    'ok' => true,
                    'collection' => $heap->qdrant_collection,
                    'points' => 0,
                    'status' => 'not_created',
                    'message' => 'Collection not created yet',
                ];
            }

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'collection' => $heap->qdrant_collection,
                    'points' => null,
                    'error' => 'Qdrant HTTP '.$response->status(),
                ];
            }

            return [
                'ok' => true,
                'collection' => $heap->qdrant_collection,
                'points' => (int) ($response->json('result.count') ?? 0),
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'collection' => $heap->qdrant_collection,
                'points' => null,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
