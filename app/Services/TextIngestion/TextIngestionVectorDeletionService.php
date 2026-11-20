<?php

declare(strict_types=1);

namespace App\Services\TextIngestion;

use App\Models\Dataset;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
final readonly class TextIngestionVectorDeletionService
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
    ) {}

    public function delete(
        Dataset $dataset,
        string $sourceId,
        string $documentId,
        string $idempotencyKey,
    ): void {
        $collection = trim((string) $dataset->qdrant_collection);
        if ($collection === '') {
            throw new \RuntimeException('The dataset has no Qdrant collection.');
        }

        $request = $this->http
            ->timeout(15)
            ->acceptJson()
            ->asJson()
            ->withHeader('X-Operation-Id', hash('sha256', $idempotencyKey));
        $apiKey = trim((string) $this->config->get(
            'model_providers.vector_stores.qdrant.api_key',
            '',
        ));
        if ($apiKey !== '') {
            $request = $request->withHeader('api-key', $apiKey);
        }

        $baseUrl = rtrim((string) $this->config->get(
            'config.qdrant_http_url',
            'http://qdrant:6333',
        ), '/');
        $response = $request->post(
            $baseUrl.'/collections/'.rawurlencode($collection).'/points/delete?wait=true',
            [
                'filter' => [
                    'must' => [
                        ['key' => 'doc_id', 'match' => ['value' => $documentId]],
                        ['key' => 'source_id', 'match' => ['value' => $sourceId]],
                        ['key' => 'dataset_id', 'match' => ['value' => (string) $dataset->dataset_id]],
                    ],
                ],
            ],
        );

        if ($response->status() === 404) {
            return;
        }

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'Qdrant rejected direct-text deletion with HTTP %d.',
                $response->status(),
            ));
        }
    }
}
