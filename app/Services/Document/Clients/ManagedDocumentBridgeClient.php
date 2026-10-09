<?php

declare(strict_types=1);

namespace App\Services\Document\Clients;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
readonly class ManagedDocumentBridgeClient
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
        private ClockInterface $clock = new Clock,
    ) {}

    /** @return array<string, mixed> */
    public function deleteOutputs(string $workflowId, array $input): array
    {
        $secret = (string) $this->config->get('temporal.callbacks.secret');
        if ($secret === '' || ! $this->config->get('temporal.enabled')) {
            throw new \RuntimeException('Authenticated Temporal deletion is not configured.');
        }
        $body = json_encode(['workflow_id' => $workflowId, 'workflow_input' => $input], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) $this->clock->now()->getTimestamp();
        $response = $this->http->timeout((int) $this->config->get('temporal.bridge_timeout', 30))
            ->acceptJson()->withHeaders([
                'X-Hawki-Timestamp' => $timestamp,
                'X-Hawki-Signature' => 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
            ])->withBody($body, 'application/json')->post(
                rtrim((string) $this->config->get('config.hawki_rag_bridge_url'), '/').'/temporal/workflows/delete-managed-document',
            );
        $response->throw();
        $result = $response->json();
        if (! is_array($result) || ($result['workflow_id'] ?? null) !== $workflowId) {
            throw new \RuntimeException('Deletion workflow acknowledgement has an unexpected identity.');
        }
        return $result;
    }
}
