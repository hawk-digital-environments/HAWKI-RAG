<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

#[Singleton]
readonly class QdrantCollectionCatalog
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
        private LoggerInterface $logger,
    ) {}

    /**
     * Return the physical Qdrant collections, keyed by their exact names.
     *
     * A null result means readiness could not be established and callers must
     * fail closed rather than expose datasets backed by unknown storage.
     *
     * @return array<string, true>|null
     */
    public function availableCollectionSet(): ?array
    {
        try {
            $request = $this->http
                ->timeout(3)
                ->connectTimeout(3)
                ->acceptJson();

            $apiKey = trim((string) $this->config->get('model_providers.vector_stores.qdrant.api_key', ''));
            if ($apiKey !== '') {
                $request = $request->withHeader('api-key', $apiKey);
            }

            $response = $request->get($this->baseUrl().'/collections');
            if (! $response->successful()) {
                $this->logger->warning('Qdrant collection catalog request failed.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $collections = $response->json('result.collections');
            if (! is_array($collections)) {
                $this->logger->warning('Qdrant collection catalog returned an invalid response.');

                return null;
            }

            $available = [];
            foreach ($collections as $collection) {
                if (! is_array($collection)) {
                    continue;
                }

                $name = trim((string) ($collection['name'] ?? ''));
                if ($name !== '') {
                    $available[$name] = true;
                }
            }

            return $available;
        } catch (\Throwable $exception) {
            $this->logger->warning('Qdrant collection catalog request failed.', [
                'exception' => $exception,
            ]);

            return null;
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) $this->config->get('config.qdrant_http_url', 'http://qdrant:6333'), '/');
    }
}
