<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Clients;

use App\Models\User;
use App\Services\Authorization\QueryTokenSigner;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Clock\ClockInterface;

#[Singleton]
readonly class PythonUrlIngestionClient
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
        private QueryTokenSigner $signer,
        private ClockInterface $clock,
    ) {}

    public function forward(User $user, string $method, string $path, array $body, ?string $key): array
    {
        try {
            $token = $this->signer->signIngest($user);
            $request = $this->http->acceptJson()->withToken($token);
            if ($key !== null) {
                $request = $request->withHeaders(['Idempotency-Key' => $key]);
            }
            $response = $request->timeout(30)->connectTimeout(3)->withoutRedirecting()
                ->withOptions(['cookies' => false, 'http_errors' => false])
                ->send($method, $this->url('/v1/'.$path), $method === 'GET' ? ['query' => $body] : ['json' => (object) $body]);
            $payload = $this->safeBody($response->body(), $token);
            if ($response->successful() && is_array($payload) && ($payload['success'] ?? null) === true) {
                if ($path === 'pipeline/tasks/start' && ($response->status() !== 201 || ! is_string($payload['task_id'] ?? null) || ! is_array($payload['task'] ?? null))) {
                    return $this->failure(502);
                }

                return ['status' => $response->status(), 'payload' => $payload];
            }

            return $this->failure(in_array($response->status(), [202, 401, 403, 404, 409, 413, 422, 503], true) ? $response->status() : 502);
        } catch (\Throwable) {
            return $this->failure(502);
        }
    }

    public function relay(string $raw, string $kind): array
    {
        try {
            $secret = $this->config->get('temporal.callbacks.secret');
            if (! is_string($secret) || $secret === '' || ! in_array($kind, ['events', 'writers', 'ownership'], true)) {
                return $this->failure(503);
            }
            $stamp = (string) $this->clock->now()->getTimestamp();
            $response = $this->http->acceptJson()->timeout(30)->connectTimeout(3)->withoutRedirecting()
                ->withOptions(['cookies' => false, 'http_errors' => false])
                ->withHeaders(['X-Hawki-Timestamp' => $stamp, 'X-Hawki-Signature' => 'v1='.hash_hmac('sha256', $stamp.'.'.$raw, $secret)])
                ->withBody($raw, 'application/json')->post($this->url('/internal/url-ingestion/'.$kind));
            $payload = $this->safeBody($response->body(), $secret);
            if (! $response->successful() || ! is_array($payload)) {
                return $this->failure(in_array($response->status(), [409, 422, 503], true) ? $response->status() : 502);
            }
            if ($kind === 'ownership' && ! in_array($payload['owner'] ?? null, ['url', 'managed', 'text', 'laravel', 'absent'], true)) {
                return $this->failure(502);
            }
            if ($kind === 'events' && (($payload['accepted'] ?? null) !== true || ! is_bool($payload['duplicate'] ?? null))) {
                return $this->failure(502);
            }
            if ($kind === 'writers' && (($payload['success'] ?? null) !== true || ! in_array($payload['status'] ?? null, ['active', 'released', 'uncertain'], true) || ! is_array($payload['targets'] ?? null))) {
                return $this->failure(502);
            }

            return ['status' => $response->status(), 'payload' => $payload];
        } catch (\Throwable) {
            return $this->failure(502);
        }
    }

    public function readiness(): array
    {
        try {
            $response = $this->http->acceptJson()->timeout(3)->connectTimeout(2)->withoutRedirecting()
                ->get($this->url('/internal/url-ingestion/readiness'));
            $body = $response->json();
            if ($response->status() !== 200 || ! is_array($body)) {
                return ['ready' => false];
            }

            return array_intersect_key($body, array_flip(['enabled', 'scraper_configured', 'workflow_control_configured', 'scraper_healthy', 'safe_fetch_supported', 'schedule_generations_supported', 'ready']));
        } catch (\Throwable) {
            return ['ready' => false];
        }
    }

    private function url(string $path): string
    {
        $base = $this->config->get('config.python_url_ingestion_url');
        $parts = is_string($base) ? parse_url($base) : false;
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new \LogicException('URL ingestion backend URL is invalid.');
        }

        return rtrim($base, '/').$path;
    }

    private function safeBody(string $raw, #[\SensitiveParameter] string $secret): ?array
    {
        if (strlen($raw) > 8 * 1024 * 1024 || ($secret !== '' && str_contains($raw, $secret))) {
            return null;
        }
        $body = json_decode($raw, true);
        $normalized = is_array($body) ? json_encode($body, JSON_UNESCAPED_SLASHES) : false;

        return is_string($normalized) && ($secret === '' || ! str_contains($normalized, $secret)) ? $body : null;
    }

    private function failure(int $status): array
    {
        return ['status' => $status, 'payload' => ['success' => false, 'message' => match ($status) {
            202 => 'URL operation is pending writer drainage or reconciliation; retry the same request.',
            401 => 'Unauthenticated.', 403 => 'Forbidden.', 404 => 'URL operation was not found.',
            409 => 'URL operation ownership conflicts or requires reconciliation.',
            413 => 'The URL ingestion request is too large.', 422 => 'The given data was invalid.',
            default => 'URL ingestion is unavailable.',
        }]];
    }
}
