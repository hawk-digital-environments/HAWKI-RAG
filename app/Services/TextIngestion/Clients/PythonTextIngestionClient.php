<?php

declare(strict_types=1);

namespace App\Services\TextIngestion\Clients;

use App\Models\User;
use App\Services\Authorization\Exceptions\QueryTokenSigningException;
use App\Services\Authorization\QueryTokenSigner;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Thin compatibility proxy to the Python-owned direct-text operation.
 *
 * The client mints a short-lived, ingest-scoped internal token, forwards only
 * the validated public fields, and maps the Python error envelope back onto
 * the legacy browser contract. It owns no pipeline state and performs no
 * dataset authorization: Python is the final grant authority.
 */
#[Singleton]
readonly class PythonTextIngestionClient
{
    private const INGEST_PATH = '/v1/text-ingestions';

    private const EVENTS_PATH = '/internal/text-ingestion/events';

    // Only the Python boundary's documented code/message pairs may pass through.
    private const ERRORS = [
        404 => [
            'dataset_not_found' => 'The requested dataset is not available.',
            'text_ingestion_not_found' => 'The requested direct-text ingestion is not available.',
        ],
        409 => [
            'text_ingestion_idempotency_conflict' => 'The idempotency key was already used with a different request.',
            'text_ingestion_source_busy' => 'The direct-text source is already being processed.',
            'text_ingestion_dataset_not_ready' => 'The requested dataset is not ready for text ingestion.',
            'text_ingestion_event_target_unavailable' => 'The worker event target is not available.',
            'text_ingestion_event_id_collision' => 'The worker event id was reused with a different payload.',
        ],
        413 => ['text_ingestion_request_too_large' => 'The text ingestion request is too large.'],
        422 => [
            'text_ingestion_invalid_request' => 'The given data was invalid.',
            'text_ingestion_event_invalid' => 'The worker event is invalid.',
        ],
        500 => ['text_ingestion_storage_failed' => 'The direct-text artifact could not be stored.'],
        502 => [
            'text_ingestion_workflow_unconfirmed' => 'The text ingestion workflow could not be confirmed. Retry with the same idempotency key.',
            'text_ingestion_deletion_failed' => 'Direct-text deletion could not be completed. Retry with the same idempotency key.',
        ],
        503 => [
            'authorization_unavailable' => 'Authorization could not be completed.',
            'text_ingestion_unavailable' => 'Text ingestion is not available.',
        ],
    ];

    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
        private QueryTokenSigner $signer,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {}

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function ingest(User $user, string $rawBody, string $idempotencyKey): array
    {
        $url = $this->ingestUrl();
        if ($url === null) {
            return $this->failure(503, 'configuration');
        }

        $token = $this->ingestToken($user);
        if ($token === null) {
            return $this->failure(503, 'signing');
        }

        try {
            $response = $this->request($token)
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->withBody($rawBody, 'application/json')->post($url);

            return $this->ingestResponse($response, $token);
        } catch (ConnectionException $error) {
            return $this->transportFailure($error);
        } catch (\Throwable) {
            return $this->failure(502, 'transport');
        }
    }

    /** @return array{payload: array<string, mixed>, status: int} */
    public function delete(User $user, string $sourceId, string $idempotencyKey): array
    {
        $base = $this->ingestUrl();
        if (preg_match('/\Asource_[0-9a-f]{32}\z/', $sourceId) !== 1) {
            return ['status' => 404, 'payload' => ['error' => 'text_ingestion_not_found', 'message' => 'The requested direct-text ingestion is not available.']];
        }
        if ($base === null) {
            return $this->failure(503, 'configuration');
        }

        $token = $this->ingestToken($user);
        if ($token === null) {
            return $this->failure(503, 'signing');
        }

        try {
            $response = $this->request($token)
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->delete($base.'/'.$sourceId);

            return $this->deleteResponse($response, $token);
        } catch (ConnectionException $error) {
            return $this->transportFailure($error);
        } catch (\Throwable) {
            return $this->failure(502, 'transport');
        }
    }

    /**
     * Relay one already-verified worker event with a fresh signature.
     *
     * @return array{payload: mixed, status: int}
     */
    public function relayEvent(string $rawBody): array
    {
        return $this->relay($rawBody, false);
    }

    /** @return array{payload: mixed, status: int} */
    public function relayWriter(string $rawBody): array
    {
        return $this->relay($rawBody, true);
    }

    /** @return array{payload: mixed, status: int} */
    private function relay(string $rawBody, bool $writer): array
    {
        $url = $this->eventsUrl();
        $secret = $this->eventSecret();
        if ($url === null || $secret === null) {
            return $this->relayFailure();
        }
        if ($writer) {
            $url = substr($url, 0, -strlen('/events')).'/writers';
        }

        $timestamp = (string) $this->clock->now()->getTimestamp();
        $signature = 'v1='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        try {
            $response = $this->http->acceptJson()
                ->timeout($this->timeout())
                ->connectTimeout(3)
                ->withoutRedirecting()
                ->withOptions(['cookies' => false, 'http_errors' => false])
                ->withHeaders([
                    'X-Hawki-Timestamp' => $timestamp,
                    'X-Hawki-Signature' => $signature,
                ])
                ->withBody($rawBody, 'application/json')
                ->post($url);

            return $this->eventResponse($response, $secret, $writer);
        } catch (ConnectionException $error) {
            return $this->transportFailure($error);
        } catch (\Throwable) {
            return $this->relayFailure();
        }
    }

    private function request(#[\SensitiveParameter] string $token): PendingRequest
    {
        return $this->http->acceptJson()->asJson()->withToken($token)
            ->timeout($this->timeout())->connectTimeout(3)->withoutRedirecting()
            ->withOptions(['cookies' => false, 'http_errors' => false]);
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    private function ingestResponse(Response $response, #[\SensitiveParameter] string $token): array
    {
        $body = $this->safeBody($response, $token);
        if ($body === null) {
            return $this->failure(502, 'response');
        }
        $status = $response->status();
        if (in_array($status, [200, 202], true) && $this->ingestShape($body)) {
            /** @var array<string, mixed> $body */
            return ['status' => $status, 'payload' => $body];
        }

        return $this->mappedError($body, $status) ?? $this->failure(502, 'response');
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    private function deleteResponse(Response $response, #[\SensitiveParameter] string $token): array
    {
        $body = $this->safeBody($response, $token);
        if ($body === null) {
            return $this->failure(502, 'response');
        }
        $status = $response->status();
        if ($status === 200 && $this->deleteShape($body)) {
            /** @var array<string, mixed> $body */
            return ['status' => $status, 'payload' => $body];
        }

        return $this->mappedError($body, $status) ?? $this->failure(502, 'response');
    }

    /**
     * @return array{payload: mixed, status: int}
     */
    private function eventResponse(Response $response, #[\SensitiveParameter] string $secret, bool $writer = false): array
    {
        $raw = $response->body();
        if (! preg_match('~\Aapplication/json(?:\s*;|\z)~i', $response->header('Content-Type'))
            || str_contains($raw, $secret)) {
            return $this->relayFailure();
        }
        try {
            $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->relayFailure();
        }
        if (! is_array($body) || $this->unsafe($body, $secret, $writer)) {
            return $this->relayFailure();
        }

        $status = $response->status();
        if (in_array($status, [200, 202], true) && ($writer ? $this->writerShape($body) : $this->eventShape($body))) {
            return ['status' => $status, 'payload' => $body];
        }
        if ($status >= 500) {
            return $this->relayFailure();
        }

        return $this->mappedError($body, $status) ?? $this->relayFailure();
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{payload: array<string, mixed>, status: int}|null
     */
    private function mappedError(array $body, int $status): ?array
    {
        if (array_keys($body) !== ['detail'] || ! is_array($body['detail'])) {
            return null;
        }
        $detail = $body['detail'];
        if (array_keys($detail) !== ['error', 'message']) {
            return null;
        }
        $error = $detail['error'] ?? null;
        $message = $detail['message'] ?? null;
        if (! is_string($error) || ! is_string($message)
            || (self::ERRORS[$status][$error] ?? null) !== $message) {
            return null;
        }

        // Preserve the legacy dataset 404 shape; every other mapped failure is
        // the text-ingestion ``{error, message}`` contract from bootstrap/app.php.
        if ($error === 'dataset_not_found') {
            return [
                'status' => 404,
                'payload' => ['message' => $message, 'error' => 'dataset_not_found'],
            ];
        }

        return [
            'status' => $status,
            'payload' => ['error' => $error, 'message' => $message],
        ];
    }

    /** @return array<string, mixed>|null */
    private function safeBody(Response $response, #[\SensitiveParameter] string $token): ?array
    {
        $raw = $response->body();
        if (! preg_match('~\Aapplication/json(?:\s*;|\z)~i', $response->header('Content-Type'))
            || str_contains($raw, $token) || str_contains($raw, 'PRIVATE KEY-----')) {
            return null;
        }
        try {
            $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($body) || $this->unsafe($body, $token)) {
            return null;
        }

        return $body;
    }

    /** @param array<string, mixed> $body */
    private function ingestShape(array $body): bool
    {
        return array_keys($body) === [
            'task_id', 'job_id', 'source_id', 'workflow_id', 'status', 'replayed',
        ]
            && is_string($body['task_id']) && preg_match('/\Atask_text_[0-9a-f]{32}\z/', $body['task_id']) === 1
            && is_string($body['job_id']) && preg_match('/\Aingest_[0-9a-f]{24}\z/', $body['job_id']) === 1
            && is_string($body['source_id']) && preg_match('/\Asource_[0-9a-f]{32}\z/', $body['source_id']) === 1
            && is_string($body['workflow_id']) && preg_match('/\Aingest-text-[0-9a-f]{32}\z/', $body['workflow_id']) === 1
            && in_array($body['status'], ['running', 'ready', 'failed', 'deleting', 'deleted'], true)
            && is_bool($body['replayed']);
    }

    /** @param array<string, mixed> $body */
    private function deleteShape(array $body): bool
    {
        return array_keys($body) === [
            'source_id', 'dataset_id', 'status', 'deleted', 'replayed',
        ]
            && is_string($body['source_id']) && preg_match('/\Asource_[0-9a-f]{32}\z/', $body['source_id']) === 1
            && is_string($body['dataset_id']) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,159}\z/', $body['dataset_id']) === 1
            && $body['status'] === 'deleted'
            && $body['deleted'] === true
            && is_bool($body['replayed']);
    }

    /** @param array<string, mixed> $body */
    private function eventShape(array $body): bool
    {
        return array_keys($body) === ['event_id', 'accepted', 'duplicate', 'ignored']
            && is_string($body['event_id']) && $body['event_id'] !== ''
            && $body['accepted'] === true
            && is_bool($body['duplicate'])
            && is_bool($body['ignored']);
    }

    /** @param array<string, mixed> $body */
    private function writerShape(array $body): bool
    {
        if (array_keys($body) !== ['success', 'status', 'targets']
            || $body['success'] !== true
            || ! in_array($body['status'], ['active', 'released', 'uncertain'], true)
            || ! is_array($body['targets']) || ! array_is_list($body['targets'])) {
            return false;
        }
        foreach ($body['targets'] as $target) {
            if (! is_array($target) || array_keys($target) !== ['doc_id', 'source_id', 'dataset_id', 'collection', 'neo4j_namespace']
                || ! is_string($target['doc_id']) || ! is_string($target['source_id'])
                || ! is_string($target['dataset_id']) || ! is_string($target['collection'])
                || $target['neo4j_namespace'] !== null) {
                return false;
            }
        }

        return true;
    }

    /** @param array<array-key, mixed> $value */
    private function unsafe(array $value, #[\SensitiveParameter] string $secret, bool $writerScope = false): bool
    {
        foreach ($value as $name => $item) {
            if ($writerScope && $name === 'neo4j_namespace' && $item === null) {
                continue; // Exact vector-only grant shape is checked by writerShape().
            }
            if (in_array(strtolower((string) $name), [
                'trace', 'traceback', 'stack', 'exception', 'debug', 'authorization',
                'private_key', 'qdrant_collection', 'qdrantcollection',
                'neo4j_namespace', 'neo4jnamespace', 'tenant_id', 'principal_id',
            ], true)
                || (is_string($item) && (str_contains($item, $secret) || str_contains($item, 'PRIVATE KEY-----')))
                || (is_float($item) && ! is_finite($item))
                || (is_array($item) && $this->unsafe($item, $secret, $writerScope))) {
                return true;
            }
        }

        return false;
    }

    private function ingestUrl(): ?string
    {
        return $this->url('config.python_text_ingestion_url', self::INGEST_PATH);
    }

    private function eventsUrl(): ?string
    {
        return $this->url('config.python_text_ingestion_events_url', self::EVENTS_PATH);
    }

    private function url(string $key, string $path): ?string
    {
        $url = $this->config->get($key);
        if (! is_string($url)) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || preg_match('/[\x00-\x20\x7f]/', $url)
            || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || isset($parts['query'])
            || ($parts['path'] ?? '') !== $path) {
            return null;
        }

        return $url;
    }

    private function timeout(): int
    {
        $configured = $this->config->get('config.python_text_ingestion_timeout', 30);

        return is_int($configured) || is_string($configured)
            ? max(1, min(300, (int) $configured))
            : 30;
    }

    private function ingestToken(User $user): ?string
    {
        try {
            return $this->signer->signIngest($user);
        } catch (QueryTokenSigningException) {
            return null;
        }
    }

    private function eventSecret(): ?string
    {
        $secret = $this->config->get('temporal.callbacks.secret');

        return is_string($secret) && trim($secret) !== '' ? $secret : null;
    }

    private function transportFailure(ConnectionException $error): array
    {
        $previous = $error->getPrevious();
        $timeout = $previous instanceof ConnectException
            && ($previous->getHandlerContext()['errno'] ?? null) === 28;

        return $this->failure($timeout ? 504 : 502, $timeout ? 'timeout' : 'connection');
    }

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    private function failure(int $status, string $category): array
    {
        $this->logger->warning('Python text-ingestion boundary failed.', [
            'status' => $status,
            'category' => $category,
        ]);

        return [
            'status' => $status,
            'payload' => [
                'error' => 'text_ingestion_backend_'.$category,
                'message' => $status === 504
                    ? 'Text ingestion timed out.'
                    : 'Text ingestion is not available.',
            ],
        ];
    }

    /** @return array{payload: array<string, mixed>, status: int} */
    private function relayFailure(): array
    {
        $this->logger->warning('Python text-ingestion event relay failed.');

        return [
            'status' => 503,
            'payload' => [
                'error' => 'text_ingestion_event_unavailable',
                'message' => 'The worker event could not be delivered.',
            ],
        ];
    }
}
