<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use App\Services\Pipeline\Values\DocumentDeduplicationBackfillCandidate;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;

#[Singleton]
readonly class DocumentDeduplicationVectorEvidenceVerifier
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
        private LoggerInterface $logger,
    ) {}

    /**
     * Return null only when every recorded bridge output still exists with its recorded hash.
     */
    public function failureReason(DocumentDeduplicationBackfillCandidate $candidate): ?string
    {
        foreach ($candidate->outputContentHashes as $bridgeDocumentId => $contentHash) {
            $result = $this->countMatchingOutput(
                scopeKey: $candidate->scopeKey,
                bridgeDocumentId: $bridgeDocumentId,
                contentHash: $contentHash,
                idField: 'doc_id',
            );

            if ($result['reason'] !== null) {
                return $result['reason'];
            }

            if ($result['count'] > 0) {
                continue;
            }

            $fallback = $this->countMatchingOutput(
                scopeKey: $candidate->scopeKey,
                bridgeDocumentId: $bridgeDocumentId,
                contentHash: $contentHash,
                idField: 'document_id',
            );

            if ($fallback['reason'] !== null) {
                return $fallback['reason'];
            }

            if ($fallback['count'] < 1) {
                $this->logger->warning('Historical deduplication output is absent or stale in Qdrant.', [
                    'scope_key' => $candidate->scopeKey,
                    'doc_id' => $candidate->documentId,
                    'bridge_document_id' => $bridgeDocumentId,
                ]);

                return 'qdrant_output_missing_or_stale';
            }
        }

        return null;
    }

    /**
     * @return array{count:int,reason:?string}
     */
    private function countMatchingOutput(
        string $scopeKey,
        string $bridgeDocumentId,
        string $contentHash,
        string $idField,
    ): array {
        try {
            $request = $this->http
                ->timeout(5)
                ->connectTimeout(3)
                ->acceptJson();
            $apiKey = $this->config->get('model_providers.vector_stores.qdrant.api_key');
            if (is_scalar($apiKey) && trim((string) $apiKey) !== '') {
                $request = $request->withHeader('api-key', trim((string) $apiKey));
            }

            $response = $request->post(
                $this->baseUrl().'/collections/'.rawurlencode($scopeKey).'/points/count',
                [
                    'filter' => [
                        'must' => [
                            ['key' => $idField, 'match' => ['value' => $bridgeDocumentId]],
                            ['key' => 'content_hash', 'match' => ['value' => $contentHash]],
                        ],
                    ],
                    'exact' => true,
                ],
            );

            if ($response->status() === 404) {
                return ['count' => 0, 'reason' => 'qdrant_collection_missing'];
            }

            if (! $response->successful()) {
                $this->logger->warning('Historical deduplication Qdrant verification failed.', [
                    'scope_key' => $scopeKey,
                    'bridge_document_id' => $bridgeDocumentId,
                    'http_status' => $response->status(),
                ]);

                return ['count' => 0, 'reason' => 'qdrant_verification_unavailable'];
            }

            return [
                'count' => max(0, (int) ($response->json('result.count') ?? 0)),
                'reason' => null,
            ];
        } catch (\Throwable $exception) {
            $this->logger->warning('Historical deduplication Qdrant verification failed.', [
                'scope_key' => $scopeKey,
                'bridge_document_id' => $bridgeDocumentId,
                'error' => $exception->getMessage(),
            ]);

            return ['count' => 0, 'reason' => 'qdrant_verification_unavailable'];
        }
    }

    private function baseUrl(): string
    {
        $url = rtrim((string) $this->config->get('config.qdrant_http_url'), '/');
        if ($url !== '') {
            return $url;
        }

        $qdrant = $this->config->get('model_providers.vector_stores.qdrant', []);

        return sprintf(
            '%s://%s:%s',
            $qdrant['scheme'] ?? 'http',
            $qdrant['host'] ?? 'qdrant',
            $qdrant['port'] ?? 6333,
        );
    }
}
