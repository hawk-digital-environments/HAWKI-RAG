<?php

declare(strict_types=1);

namespace App\Services\Assistant\Clients;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;

#[Singleton]
readonly class AssistantDocumentBridgeClient
{
    public function __construct(
        private ConfigRepository $config,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteDocument(string $bridgeDocumentId, ?string $idempotencyKey = null): array
    {
        $headers = [];
        if (is_string($idempotencyKey) && trim($idempotencyKey) !== '') {
            $headers['Idempotency-Key'] = trim($idempotencyKey);
        }

        $response = Http::timeout($this->timeout())
            ->acceptJson()
            ->withHeaders($headers)
            ->delete($this->bridgeUrl().'/documents/'.rawurlencode($bridgeDocumentId));

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

        $this->logger->info('Assistant document deletion requested through Python bridge.', [
            'bridge_document_id' => $bridgeDocumentId,
            'idempotency_key' => $headers['Idempotency-Key'] ?? null,
        ]);

        return $body;
    }

    private function bridgeUrl(): string
    {
        return rtrim((string) $this->config->get('config.hawki_rag_bridge_url', 'http://hawki_rag_bridge:8000'), '/');
    }

    private function timeout(): int
    {
        return max(1, (int) $this->config->get('temporal.bridge_timeout', 30));
    }
}
