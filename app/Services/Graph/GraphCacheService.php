<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Services\Graph\Exceptions\GraphCacheException;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class GraphCacheService
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function clearBridgeCache(): array
    {
        $baseUrl = rtrim((string) $this->config->get('config.hawki_rag_bridge_url', 'http://hawki_rag_bridge:8000'), '/');

        try {
            $response = $this->http->timeout(30)
                ->acceptJson()
                ->post($baseUrl.'/graph/cache/clear');

            if ($response->failed()) {
                $exception = GraphCacheException::bridgeClearFailed($response->status());

                return [
                    'ok' => false,
                    'status' => $response->status(),
                    'message' => $exception->getMessage(),
                ];
            }

            return $response->json() ?? ['ok' => true];
        } catch (\Throwable $exception) {
            $graphException = $exception instanceof GraphCacheException
                ? $exception
                : GraphCacheException::bridgeClearRequestFailed($exception);

            return [
                'ok' => false,
                'message' => $graphException->getMessage(),
            ];
        }
    }

}
