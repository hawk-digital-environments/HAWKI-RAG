<?php

declare(strict_types=1);

namespace App\Services\Rag\Clients;

use App\Models\User;
use App\Services\Authorization\Exceptions\QueryTokenSigningException;
use App\Services\Authorization\QueryTokenSigner;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;

#[Singleton]
readonly class PythonQueryClient
{
    private const FIELDS = ['dataset_id', 'query', 'top_k', 'is_optimized', 'generate', 'fast_mode', 'smart_lookup', 'preferred_tags', 'filters'];

    // Only the Python boundary's documented generic error messages may pass through.
    private const ERRORS = [
        400 => ['invalid_query' => 'The query could not be processed.'],
        404 => ['dataset_not_found' => 'The requested dataset is not available.'],
        409 => ['dataset_not_ready' => 'The requested dataset is not ready for querying.'],
        500 => ['query_failed' => 'Query execution failed.', 'embedding_failed' => 'Embedding generation failed.'],
        502 => ['answer_generation_failed' => 'Answer generation failed.'],
        503 => ['authorization_unavailable' => 'Authorization could not be completed.', 'query_unavailable' => 'Query execution is not available.'],
    ];

    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
        private QueryTokenSigner $signer,
        private LoggerInterface $logger,
    ) {}

    /** @param array<string, mixed> $data
     * @return array{payload: mixed, status: int}
     */
    public function query(User $user, array $data): array
    {
        $url = $this->config->get('config.python_query_url');
        $parts = is_string($url) ? parse_url($url) : false;
        if ($parts === false || preg_match('/[\x00-\x20\x7f]/', $url)
            || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || isset($parts['query'])
            || ($parts['path'] ?? '') !== '/v1/query') {
            return $this->failure(503, 'configuration');
        }

        $payload = array_intersect_key($data, array_flip(self::FIELDS));
        if (isset($payload['top_k'])) {
            $payload['top_k'] = (int) $payload['top_k'];
        }
        foreach (['is_optimized', 'generate', 'fast_mode', 'smart_lookup'] as $name) {
            if (isset($payload[$name])) {
                $payload[$name] = filter_var($payload[$name], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (empty($payload['filters'])) {
            unset($payload['filters']);
        }
        try {
            $token = $this->signer->sign($user);
        } catch (QueryTokenSigningException) {
            return $this->failure(503, 'signing');
        }

        try {
            $response = $this->http->acceptJson()->asJson()->withToken($token)
                ->timeout(max(1, (int) $this->config->get('config.hawki_rag_query_timeout', 300)))
                ->connectTimeout(3)->withoutRedirecting()
                ->withOptions(['cookies' => false, 'http_errors' => false])
                ->post($url, $payload);

            return $this->response($response, $token);
        } catch (ConnectionException $error) {
            $previous = $error->getPrevious();
            $timeout = $previous instanceof ConnectException && ($previous->getHandlerContext()['errno'] ?? null) === 28;

            return $this->failure($timeout ? 504 : 502, $timeout ? 'timeout' : 'connection');
        } catch (\Throwable) {
            return $this->failure(502, 'transport');
        }
    }

    /** @return array{payload: mixed, status: int} */
    private function response(Response $response, #[\SensitiveParameter] string $token): array
    {
        $raw = $response->body();
        if (! preg_match('~\Aapplication/json(?:\s*;|\z)~i', $response->header('Content-Type'))
            || str_contains($raw, $token) || str_contains($raw, 'PRIVATE KEY-----')) {
            return $this->failure(502, 'response');
        }
        try {
            $body = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            // Keep JSON objects as objects, including empty hit payloads/retrieval.
            $payload = json_decode($raw, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->failure(502, 'response');
        }
        if (! is_array($body) || $this->unsafeData($body, $token)) {
            return $this->failure(502, 'response');
        }
        $status = $response->status();
        if ($status === 200 && $this->queryResponse($body, $payload)) {
            return ['status' => $status, 'payload' => $payload];
        }
        if (array_keys($body) === ['detail']) {
            $detail = $body['detail'];
            $auth = [401 => ['authentication_required', 'invalid_token'], 403 => ['insufficient_scope'], 503 => ['authentication_unavailable']];
            if (is_string($detail) && in_array($detail, $auth[$status] ?? [], true)) {
                return ['status' => $status, 'payload' => $payload];
            }
            if (is_array($detail) && count($detail) === 2
                && isset($detail['error'], $detail['message']) && is_string($detail['error'])
                && (self::ERRORS[$status][$detail['error']] ?? null) === $detail['message']) {
                return ['status' => $status, 'payload' => $payload];
            }
            if ($status === 422 && $this->validationErrors($detail)) {
                return ['status' => $status, 'payload' => $payload];
            }
        }

        return $this->failure(502, 'response');
    }

    /** @param array<string, mixed> $body */
    private function queryResponse(array $body, \stdClass $decoded): bool
    {
        if (! (count($body) === 6 && ($body['ok'] ?? null) === true
            && is_int($body['count'] ?? null) && $body['count'] >= 0
            && is_string($body['answer'] ?? null)
            && is_array($body['hits'] ?? null) && array_is_list($body['hits'])
            && is_array($body['kg'] ?? null) && array_is_list($body['kg'])
            && ($decoded->retrieval ?? null) instanceof \stdClass)) {
            return false;
        }
        foreach ($decoded->hits as $hit) {
            if (! $hit instanceof \stdClass || ! ($hit->payload ?? null) instanceof \stdClass
                || (isset($hit->id) && ! is_string($hit->id) && ! is_int($hit->id))
                || (isset($hit->score) && ! is_int($hit->score) && ! is_float($hit->score))) {
                return false;
            }
        }
        foreach ($decoded->kg as $fact) {
            if (! $fact instanceof \stdClass || array_filter((array) $fact, static fn ($value): bool => ! is_string($value))) {
                return false;
            }
        }

        return true;
    }

    private function validationErrors(mixed $errors): bool
    {
        if (! is_array($errors) || ! array_is_list($errors) || $errors === []) {
            return false;
        }
        foreach ($errors as $error) {
            if (! is_array($error) || array_diff(array_keys($error), ['type', 'loc', 'msg', 'input', 'ctx'])
                || ! is_string($error['type'] ?? null) || ! is_string($error['msg'] ?? null)
                || ! is_array($error['loc'] ?? null) || ! array_is_list($error['loc'])
                || ($error['loc'][0] ?? null) !== 'body'
                || ! in_array($error['loc'][1] ?? '', self::FIELDS, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<array-key, mixed> $value */
    private function unsafeData(array $value, #[\SensitiveParameter] string $token): bool
    {
        foreach ($value as $name => $item) {
            if (in_array(strtolower((string) $name), ['trace', 'traceback', 'stack', 'exception', 'debug', 'authorization', 'private_key', 'qdrant_collection', 'qdrantcollection', 'neo4j_namespace', 'neo4jnamespace'], true)
                || (is_string($item) && (str_contains($item, $token) || str_contains($item, 'PRIVATE KEY-----')))
                || (is_float($item) && ! is_finite($item))
                || (is_array($item) && $this->unsafeData($item, $token))) {
                return true;
            }
        }

        return false;
    }

    /** @return array{payload: array<string, mixed>, status: int} */
    private function failure(int $status, string $category): array
    {
        $this->logger->warning('Python query boundary failed.', ['status' => $status, 'category' => $category]);

        return ['status' => $status, 'payload' => ['detail' => [
            'error' => 'query_backend_'.$category,
            'message' => $status === 504 ? 'Query execution timed out.' : 'Query execution is not available.',
        ]]];
    }
}
