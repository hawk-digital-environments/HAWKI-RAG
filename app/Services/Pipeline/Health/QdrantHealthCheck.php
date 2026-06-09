<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Health;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class QdrantHealthCheck
{
    private const FIX = 'Start qdrant and verify QDRANT_HTTP_URL, QDRANT_HOST, QDRANT_PORT, and QDRANT_API_KEY.';

    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
    ) {
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function check(int $timeout): array
    {
        $url = $this->url();

        try {
            $request = $this->http->timeout($timeout)->connectTimeout($timeout)->acceptJson();
            if ($apiKey = $this->config->get('model_providers.vector_stores.qdrant.api_key')) {
                $request = $request->withHeader('api-key', (string) $apiKey);
            }

            $response = $request->get($url.'/collections');
            if ($response->successful()) {
                return $this->ok('Qdrant', 'Connected to '.$url.'.');
            }

            return $this->failureResult('Qdrant', "HTTP {$response->status()} from {$url}/collections.", self::FIX);
        } catch (\Throwable $exception) {
            return $this->failureResult('Qdrant', $exception->getMessage(), self::FIX);
        }
    }

    private function url(): string
    {
        $url = rtrim((string) $this->config->get('config.qdrant_http_url'), '/');
        if ($url !== '') {
            return $url;
        }

        $qdrant = $this->config->get('model_providers.vector_stores.qdrant', []);

        return sprintf('%s://%s:%s', $qdrant['scheme'] ?? 'http', $qdrant['host'] ?? 'qdrant', $qdrant['port'] ?? 6333);
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    private function ok(string $name, string $detail): array
    {
        return [
            'name' => $name,
            'status' => 'ok',
            'detail' => $detail,
            'fix' => '',
        ];
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    private function failureResult(string $name, string $detail, string $fix): array
    {
        return [
            'name' => $name,
            'status' => 'fail',
            'detail' => $detail,
            'fix' => $fix,
        ];
    }
}
