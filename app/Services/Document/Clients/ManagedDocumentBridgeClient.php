<?php

declare(strict_types=1);

namespace App\Services\Document\Clients;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;

#[Singleton]
readonly class ManagedDocumentBridgeClient
{
    public function __construct(
        private ConfigRepository $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteDocument(
        string $bridgeDocumentId,
        ?string $idempotencyKey = null,
        ?string $collection = null,
        ?string $neo4jNamespace = null,
    ): array {
        $headers = [];
        if (is_string($idempotencyKey) && trim($idempotencyKey) !== '') {
            $headers['Idempotency-Key'] = trim($idempotencyKey);
        }

        $query = array_filter([
            'collection' => $this->stringValue($collection),
            'neo4j_namespace' => $this->stringValue($neo4jNamespace),
        ], static fn (?string $value): bool => $value !== null);

        $url = $this->bridgeUrl().'/documents/'.rawurlencode($bridgeDocumentId);
        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        $response = Http::timeout($this->timeout())
            ->acceptJson()
            ->withHeaders($headers)
            ->delete($url);

        if (! $response->successful()) {
            throw new \RuntimeException(sprintf(
                'Python bridge document delete failed [%s %s]: %s',
                $response->status(),
                $bridgeDocumentId,
                $response->body(),
            ));
        }

        $body = $response->json();
        if (! is_array($body)) {
            throw new \RuntimeException('Python bridge document delete returned non-object JSON.');
        }

        $this->logger->info('Managed document deletion requested through Python bridge.', [
            'bridge_document_id' => $bridgeDocumentId,
            'idempotency_key' => $headers['Idempotency-Key'] ?? null,
            'collection' => $query['collection'] ?? null,
            'neo4j_namespace' => $query['neo4j_namespace'] ?? null,
        ]);

        return $body;
    }

    private function bridgeUrl(): string
    {
        return rtrim((string) $this->config->get('config.hawki_rag_bridge_url', 'http://hawki_rag_bridge'), '/');
    }

    private function timeout(): int
    {
        return max(1, (int) $this->config->get('temporal.bridge_timeout', 30));
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
