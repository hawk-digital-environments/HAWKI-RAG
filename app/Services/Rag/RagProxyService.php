<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Services\Rag\Values\RagQueryPayload;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class RagProxyService
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
    ) {}

    /**
     * @return array{payload: mixed, status: int}
     */
    public function query(RagQueryPayload $payload): array
    {
        try {
            $response = $this->http->timeout(60)->post($this->queryEndpoint(), $payload->toArray());
        } catch (\Throwable $exception) {
            return [
                'status' => 502,
                'payload' => [
                    'ok' => false,
                    'message' => 'Failed to reach HAWKI RAG bridge',
                    'error' => $exception->getMessage(),
                ],
            ];
        }

        $json = $response->json();
        if ($json === null) {
            return [
                'status' => 502,
                'payload' => [
                    'ok' => false,
                    'message' => 'HAWKI RAG bridge returned an invalid response',
                    'status' => $response->status(),
                    'body' => $response->body(),
                ],
            ];
        }

        return [
            'status' => $response->status(),
            'payload' => $json,
        ];
    }

    private function queryEndpoint(): string
    {
        return rtrim((string) $this->config->get('config.hawki_rag_bridge_url'), '/').'/query';
    }
}
