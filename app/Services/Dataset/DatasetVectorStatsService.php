<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use App\Models\Dataset;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class DatasetVectorStatsService
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(Dataset $dataset): array
    {
        $baseUrl = rtrim((string) $this->config->get('config.qdrant_http_url', 'http://qdrant:6333'), '/');

        try {
            $response = $this->http->timeout(3)->post($baseUrl.'/collections/'.rawurlencode($dataset->qdrant_collection).'/points/count', [
                'exact' => true,
            ]);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'collection' => $dataset->qdrant_collection,
                    'points' => null,
                    'error' => 'Qdrant HTTP '.$response->status(),
                ];
            }

            return [
                'ok' => true,
                'collection' => $dataset->qdrant_collection,
                'points' => (int) ($response->json('result.count') ?? 0),
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'collection' => $dataset->qdrant_collection,
                'points' => null,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
