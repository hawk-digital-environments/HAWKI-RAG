<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use App\Models\User;
use App\Services\Authorization\Exceptions\QueryTokenSigningException;
use App\Services\Authorization\QueryTokenSigner;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;

/**
 * The BFF's only client of the Python dataset authority.
 *
 * It mints an internal token for every call, forwards only validated public
 * fields, and maps the Python dataset envelope back onto the legacy Laravel
 * contract. It never reads or writes Laravel's public dataset tables and never
 * falls back to the legacy implementation.
 */
#[Singleton]
readonly class PythonDatasetClient
{
    private const DATASET_PATH = '/v1/datasets';

    private const QUERY_DATASETS_PATH = '/v1/query/datasets';

    private const MAX_BODY_BYTES = 2_000_000;

    /** The only conflict fields the Python authority may report. */
    private const CONFLICT_FIELDS = [
        'dataset_id',
        'name',
        'description',
        'status',
        'physical_scope',
    ];

    /**
     * Only these Python dataset failures may reach a Laravel caller. Each
     * message is the exact legacy message for the same error code.
     */
    private const ERRORS = [
        404 => [
            'dataset_not_found' => 'The requested dataset is not available.',
        ],
        409 => [
            'dataset_not_ready' => 'The requested dataset is not ready for querying.',
            'dataset_creation_conflict' => null,
        ],
        403 => [
            'grant_token_required' => 'A one-time dataset grant token is required to self-grant ingest access.',
            'grant_token_invalid' => 'The provided dataset grant token is invalid, expired, or already used.',
        ],
        422 => [
            'invalid_dataset_request' => 'The given data was invalid.',
        ],
        503 => [
            'dataset_unavailable' => 'The dataset service is not available.',
        ],
    ];

    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
        private QueryTokenSigner $signer,
        private LoggerInterface $logger,
    ) {}

    /** @return array{status:int, payload:array<string, mixed>} */
    public function list(int $limit = 50): array
    {
        return $this->request('GET', $this->datasetUrl(), $this->adminToken(), [
            'query' => ['limit' => max(1, min(250, $limit))],
        ], 'list');
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    public function show(string $datasetId): array
    {
        $id = $this->datasetId($datasetId);
        if ($id === null) {
            return $this->notFound('show', $datasetId);
        }

        return $this->request('GET', $this->datasetUrl().'/'.rawurlencode($id), $this->adminToken(), [], 'show', $id);
    }

    /** @param array<string, mixed> $input
     * @return array{status:int, payload:array<string, mixed>}
     */
    public function create(array $input): array
    {
        return $this->request('POST', $this->datasetUrl(), $this->adminToken(), [
            'json' => $input,
        ], 'create', $this->requestedDatasetId($input));
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    public function delete(string $datasetId): array
    {
        $id = $this->datasetId($datasetId);
        if ($id === null) {
            return $this->notFound('delete', $datasetId);
        }

        return $this->request(
            'DELETE',
            $this->datasetUrl().'/'.rawurlencode($id).'/storage',
            $this->adminToken(),
            [],
            'delete',
            $id,
        );
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    public function grantQuerySelf(User $user, string $datasetId): array
    {
        $id = $this->datasetId($datasetId);
        if ($id === null) {
            return $this->notFound('grant', $datasetId);
        }

        return $this->request(
            'POST',
            $this->datasetUrl().'/'.rawurlencode($id).'/query-grants/self',
            $this->userToken($user, false),
            [],
            'grant_query',
            $id,
        );
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    public function grantIngestSelf(User $user, string $datasetId, ?string $grantToken): array
    {
        $id = $this->datasetId($datasetId);
        if ($id === null) {
            return $this->notFound('grant', $datasetId);
        }

        return $this->request(
            'POST',
            $this->datasetUrl().'/'.rawurlencode($id).'/ingest-grants/self',
            $this->userToken($user, true),
            $grantToken === null ? [] : ['json' => ['grant_token' => $grantToken]],
            'grant_ingest',
            $id,
        );
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    public function queryDatasets(User $user): array
    {
        return $this->request(
            'GET',
            $this->queryDatasetsUrl(),
            $this->userToken($user, false),
            [],
            'query_datasets',
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{status:int, payload:array<string, mixed>}
     */
    private function request(
        string $method,
        ?string $url,
        ?string $token,
        array $options,
        string $purpose,
        ?string $expectedDatasetId = null,
    ): array {
        if ($url === null || $token === null) {
            return $this->failure();
        }

        try {
            $request = $this->http->acceptJson()
                ->withToken($token)
                ->timeout($this->timeout())
                ->connectTimeout(3)
                ->withoutRedirecting()
                ->withOptions(['cookies' => false, 'http_errors' => false]);
            $response = $request->send($method, $url, $options);

            return $this->response($response, $purpose, $expectedDatasetId);
        } catch (ConnectionException) {
            $this->logger->warning('Python dataset boundary is unreachable.', ['purpose' => $purpose]);

            return $this->failure();
        } catch (\Throwable) {
            // Never log a token, request body, upstream body or private key.
            $this->logger->warning('Python dataset boundary failed.', ['purpose' => $purpose]);

            return $this->failure();
        }
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    private function response(
        Response $response,
        string $purpose,
        ?string $expectedDatasetId = null,
    ): array {
        $body = $this->safeBody($response, $purpose === 'create');
        if ($body === null) {
            return $this->failure();
        }

        $status = $response->status();
        if ($status === 404) {
            $message = $body['message'] ?? null;
            if (($body['success'] ?? null) === false && is_string($message)) {
                return ['status' => 404, 'payload' => ['success' => false, 'message' => $message]];
            }
            if (($body['error'] ?? null) === 'dataset_not_found' && is_string($message)) {
                return ['status' => 404, 'payload' => ['message' => $message, 'error' => 'dataset_not_found']];
            }

            return $this->failure();
        }
        if ($status >= 400) {
            $error = $body['error'] ?? null;
            $message = $body['message'] ?? null;
            if (! is_string($error) || ! is_string($message)) {
                return $this->failure();
            }
            if (! array_key_exists($status, self::ERRORS)
                || ! array_key_exists($error, self::ERRORS[$status])) {
                return $this->failure();
            }
            $expected = self::ERRORS[$status][$error];
            if ($expected !== null && $message !== $expected) {
                return $this->failure();
            }
            if ($expected === null && ! $this->conflictMessage($message, $expectedDatasetId)) {
                return $this->failure();
            }

            return ['status' => $status, 'payload' => ['error' => $error, 'message' => $message]];
        }

        if (($body['success'] ?? null) !== true) {
            return $this->failure();
        }

        return match ($purpose) {
            'list' => $this->listResponse($body),
            'show' => $this->showResponse($body, $expectedDatasetId),
            'create' => $this->createResponse($status, $body, $expectedDatasetId),
            'delete' => $this->deleteResponse($status, $body, $expectedDatasetId),
            'grant_query' => $this->grantResponse($body, 'query_access', false, $expectedDatasetId),
            'grant_ingest' => $this->grantResponse($body, 'ingest_access', true, $expectedDatasetId),
            'query_datasets' => $this->queryDatasetsResponse($body),
            default => $this->failure(),
        };
    }

    /** @param array<string, mixed> $body
     * @return array{status:int, payload:array<string, mixed>}
     */
    private function listResponse(array $body): array
    {
        $datasets = $body['datasets'] ?? null;
        if (! is_array($datasets) || ! array_is_list($datasets)) {
            return $this->failure();
        }
        foreach ($datasets as $dataset) {
            if (! is_array($dataset) || ! is_string($dataset['dataset_id'] ?? null)
                || ! is_string($dataset['name'] ?? null)) {
                return $this->failure();
            }
        }

        return ['status' => 200, 'payload' => ['success' => true, 'datasets' => $datasets]];
    }

    /** @param array<string, mixed> $body
     * @return array{status:int, payload:array<string, mixed>}
     */
    private function showResponse(array $body, ?string $expectedDatasetId): array
    {
        $dataset = $body['dataset'] ?? null;
        if (! is_array($dataset) || ! $this->sameDatasetId($dataset['dataset_id'] ?? null, $expectedDatasetId)) {
            return $this->failure();
        }

        return ['status' => 200, 'payload' => ['success' => true, 'dataset' => $dataset]];
    }

    /** @param array<string, mixed> $body
     * @return array{status:int, payload:array<string, mixed>}
     */
    private function createResponse(int $status, array $body, ?string $expectedDatasetId): array
    {
        if (! in_array($status, [200, 201], true)
            || ! $this->sameDatasetId($body['dataset_id'] ?? null, $expectedDatasetId)
            || ! is_array($body['dataset'] ?? null)
            || ! $this->sameDatasetId($body['dataset']['dataset_id'] ?? null, $expectedDatasetId)) {
            return $this->failure();
        }
        $token = $body['grant_token'] ?? null;
        if ($token !== null && (! is_string($token) || preg_match('/\Adgt_[0-9a-f]{64}\z/', $token) !== 1)) {
            return $this->failure();
        }

        return ['status' => $status, 'payload' => $body];
    }

    /** @param array<string, mixed> $body
     * @return array{status:int, payload:array<string, mixed>}
     */
    private function deleteResponse(int $status, array $body, ?string $expectedDatasetId): array
    {
        $cleanup = $body['cleanup'] ?? null;
        if (! in_array($status, [200, 202], true)
            || ! is_array($cleanup)
            || ! $this->sameDatasetId($cleanup['dataset_id'] ?? null, $expectedDatasetId)
            || ! is_string($cleanup['status'] ?? null)
            || ! is_bool($cleanup['cleanup_pending'] ?? null)) {
            return $this->failure();
        }

        return ['status' => $status, 'payload' => $body];
    }

    /** @param array<string, mixed> $body
     * @return array{status:int, payload:array<string, mixed>}
     */
    private function grantResponse(
        array $body,
        string $field,
        bool $replayed,
        ?string $expectedDatasetId,
    ): array {
        $access = $body[$field] ?? null;
        $permission = $field === 'query_access' ? 'query' : 'ingest';
        if (! $this->sameDatasetId($body['dataset_id'] ?? null, $expectedDatasetId)
            || ! is_array($access)
            || ($access['granted'] ?? null) !== true
            || ($access['permission'] ?? null) !== $permission
            || ($replayed && ! is_bool($body['replayed'] ?? null))) {
            return $this->failure();
        }

        return ['status' => 200, 'payload' => $body];
    }

    /** @param array<string, mixed> $body
     * @return array{status:int, payload:array<string, mixed>}
     */
    private function queryDatasetsResponse(array $body): array
    {
        $datasets = $body['datasets'] ?? null;
        if (! is_array($datasets) || ! array_is_list($datasets)) {
            return $this->failure();
        }
        foreach ($datasets as $dataset) {
            if (! is_array($dataset) || ! is_string($dataset['dataset_id'] ?? null)
                || ! is_string($dataset['name'] ?? null)) {
                return $this->failure();
            }
        }

        return ['status' => 200, 'payload' => ['datasets' => $datasets]];
    }

    private function conflictMessage(string $message, ?string $expectedDatasetId): bool
    {
        if ($expectedDatasetId === null) {
            return false;
        }

        $pattern = '/\ADataset \['.preg_quote($expectedDatasetId, '/')
            .'\] already exists with a different \[([a-z_]+)\]\.\z/';
        if (preg_match($pattern, $message, $matches) !== 1) {
            return false;
        }

        return in_array($matches[1], self::CONFLICT_FIELDS, true);
    }

    /** @return array<string, mixed>|null */
    private function safeBody(Response $response, bool $allowGrantToken = false): ?array
    {
        if (! preg_match('~\Aapplication/json(?:\s*;|\z)~i', $response->header('Content-Type'))) {
            return null;
        }
        $raw = $response->body();
        if (strlen($raw) > self::MAX_BODY_BYTES || str_contains($raw, 'PRIVATE KEY-----')) {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($decoded) || $this->unsafe($decoded, $allowGrantToken)) {
            return null;
        }

        return $decoded;
    }

    /** @param array<array-key, mixed> $value */
    private function unsafe(array $value, bool $allowGrantToken = false): bool
    {
        foreach ($value as $name => $item) {
            $key = (string) $name;
            $lower = strtolower($key);

            // Only the top-level grant_token of a create response may carry the
            // one-time token, and only in its exact shape. A nested field, a
            // token-shaped key or a token-shaped value anywhere else is never
            // trusted. A name such as dgt_report_2024 does not match.
            if ($allowGrantToken && $lower === 'grant_token'
                && is_string($item)
                && preg_match('/\Adgt_[0-9a-f]{64}\z/', $item) === 1) {
                continue;
            }
            if (in_array($lower, ['trace', 'traceback', 'stack', 'exception', 'debug', 'authorization', 'private_key'], true)
                || $this->containsSecret($key)
                || (is_string($item) && $this->containsSecret($item))
                || (is_float($item) && ! is_finite($item))
                || (is_array($item) && $this->unsafe($item))) {
                return true;
            }
        }

        return false;
    }

    private function containsSecret(string $value): bool
    {
        return preg_match('/dgt_[0-9a-f]{64}/', $value) === 1
            || str_contains($value, 'PRIVATE KEY-----');
    }

    /** @param array<string, mixed> $input */
    private function requestedDatasetId(array $input): string
    {
        $value = $input['dataset_id'] ?? $input['datasetId'] ?? null;
        if (! is_scalar($value)) {
            return 'default';
        }
        $id = trim((string) $value);

        return $id !== '' ? $id : 'default';
    }

    private function sameDatasetId(mixed $actual, ?string $expected): bool
    {
        return $expected !== null && is_string($actual) && hash_equals($expected, $actual);
    }

    private function datasetUrl(): ?string
    {
        return $this->validatedUrl('config.python_dataset_url', self::DATASET_PATH);
    }

    private function queryDatasetsUrl(): ?string
    {
        return $this->validatedUrl('config.python_query_datasets_url', self::QUERY_DATASETS_PATH);
    }

    private function validatedUrl(string $key, string $path): ?string
    {
        $url = $this->config->get($key);
        if (! is_string($url) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment']) || isset($parts['query'])
            || ($parts['path'] ?? '') !== $path) {
            return null;
        }

        return $url;
    }

    private function adminToken(): ?string
    {
        try {
            return $this->signer->signDatasetAdmin();
        } catch (QueryTokenSigningException) {
            $this->logger->warning('Python dataset admin token could not be signed.');

            return null;
        }
    }

    private function userToken(User $user, bool $ingest): ?string
    {
        try {
            return $ingest ? $this->signer->signIngest($user) : $this->signer->sign($user);
        } catch (QueryTokenSigningException) {
            $this->logger->warning('Python dataset user token could not be signed.');

            return null;
        }
    }

    private function datasetId(string $datasetId): ?string
    {
        $id = trim($datasetId);

        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\z/', $id) === 1 ? $id : null;
    }

    private function timeout(): int
    {
        $configured = $this->config->get('config.python_dataset_timeout');

        return is_int($configured) && $configured >= 1 && $configured <= 60 ? $configured : 10;
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    private function notFound(string $purpose, ?string $datasetId = null): array
    {
        if (in_array($purpose, ['show', 'delete'], true)) {
            return ['status' => 404, 'payload' => [
                'success' => false,
                'message' => "Dataset {$datasetId} was not found.",
            ]];
        }

        return ['status' => 404, 'payload' => [
            'error' => 'dataset_not_found',
            'message' => 'The requested dataset is not available.',
        ]];
    }

    /** @return array{status:int, payload:array<string, mixed>} */
    private function failure(): array
    {
        return ['status' => 503, 'payload' => [
            'error' => 'dataset_unavailable',
            'message' => 'The dataset service is not available.',
        ]];
    }
}
