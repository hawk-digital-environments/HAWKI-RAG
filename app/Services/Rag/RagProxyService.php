<?php

declare(strict_types=1);

namespace App\Services\Rag;

use App\Models\User;
use App\Services\Authorization\DatasetQueryAuthorizationService;
use App\Services\Settings\SettingsService;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class RagProxyService
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
        private DatasetQueryAuthorizationService $authorization,
        private SettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{payload: mixed, status: int}
     */
    public function query(User $user, array $data): array
    {
        $scope = $this->authorization->authorize($user, (string) $data['dataset_id']);
        $modelRuntime = $this->settings->modelRuntime();
        $payload = [
            'query' => $data['query'],
            'top_k' => $data['top_k'] ?? 5,
            'provider' => $modelRuntime['provider'],
            'chat_model' => $modelRuntime['graph_model'],
            'vision_model' => $modelRuntime['vision_model'],
            'is_optimized' => $data['is_optimized'] ?? false,
            'generate' => $data['generate'] ?? true,
            'fast_mode' => $data['fast_mode'] ?? false,
            'smart_lookup' => $data['smart_lookup'] ?? false,
            'authorized_scope' => $scope->toArray(),
        ];

        if (! empty($data['preferred_tags'])) {
            $payload['preferred_tags'] = $data['preferred_tags'];
        }

        if (! empty($data['filters'])) {
            $payload['filters'] = $data['filters'];
        }

        try {
            $response = $this->http
                ->timeout(max(1, (int) $this->config->get('config.hawki_rag_query_timeout', 300)))
                ->post($this->queryEndpoint(), $payload);
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
