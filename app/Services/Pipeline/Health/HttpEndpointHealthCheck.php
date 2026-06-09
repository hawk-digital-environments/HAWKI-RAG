<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Health;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class HttpEndpointHealthCheck
{
    public function __construct(private HttpFactory $http)
    {
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function reachabilityCheck(string $name, string $url, int $timeout, string $detail, string $fix): array
    {
        try {
            $response = $this->http->timeout($timeout)->connectTimeout($timeout)->acceptJson()->get($url);
            if ($response->status() < 500) {
                return $this->ok($name, "{$detail} Service reachable at {$url} with HTTP {$response->status()}.");
            }

            return $this->failureResult($name, "HTTP {$response->status()} from {$url}.", $fix);
        } catch (\Throwable $exception) {
            return $this->failureResult($name, $exception->getMessage(), $fix);
        }
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function successCheck(string $name, string $url, int $timeout, string $detail, string $fix): array
    {
        try {
            $response = $this->http->timeout($timeout)->connectTimeout($timeout)->acceptJson()->get($url);
            if ($response->successful()) {
                return $this->ok($name, "{$detail} Service healthy at {$url}.");
            }

            return $this->failureResult($name, "HTTP {$response->status()} from {$url}.", $fix);
        } catch (\Throwable $exception) {
            return $this->failureResult($name, $exception->getMessage(), $fix);
        }
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
