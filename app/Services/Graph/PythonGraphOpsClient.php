<?php

declare(strict_types=1);

namespace App\Services\Graph;

use App\Models\User;
use App\Services\Authorization\QueryTokenSigner;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;

final readonly class PythonGraphOpsClient
{
    public function __construct(private Repository $config, private Factory $http, private QueryTokenSigner $signer) {}

    /** @return array<string, mixed> */
    public function request(string $action, array $data = [], ?User $user = null, bool $manager = false): array
    {
        if (! in_array($action, ['graph/overview', 'graph/search', 'graph/node', 'graph/expand', 'graph/semantic-search', 'graph/clear', 'graph/clear-admission', 'graph/clear-receipt', 'graph/scope', 'stats', 'config'], true)
            || ! GraphOpsBackend::validUrl($this->config->get('config.python_graph_ops_url'))) {
            return $this->unavailable();
        }
        try {
            $token = $manager ? $this->signer->signDatasetAdmin() : $this->signer->sign($user);
            $client = $this->http->acceptJson()->asJson()->withToken($token)
                ->connectTimeout(3)->timeout((int) $this->config->get('config.python_graph_ops_timeout', 10))
                ->withoutRedirecting()->withOptions(['cookies' => false, 'http_errors' => false]);
            $url = rtrim($this->config->get('config.python_graph_ops_url'), '/').'/'.$action;
            $response = in_array($action, ['graph/expand', 'graph/clear', 'graph/clear-admission', 'graph/clear-receipt'], true)
                ? $client->post($url, $data) : $client->get($url, $data);
            if ($response->successful() && is_array($response->json())
                && $this->validResponse($action, $data, $response->json())
                && ! str_contains($response->body(), $token)
                && ! str_contains($response->body(), 'PRIVATE KEY-----')) {
                return $response->json();
            }
            $detail = $response->json('detail');
            if (in_array($response->status(), [401, 403, 404, 409, 422], true)) {
                $safe = in_array($detail, ['dataset_not_found', 'dataset_graph_disabled', 'graph_writers_active_or_unconfirmed', 'graph_clear_reconciliation_required', 'insufficient_scope'], true) ? $detail : 'graph_ops_request_refused';

                return ['ok' => false, 'status' => $response->status(), 'error' => $safe, 'message' => 'Graph operations request refused.'];
            }
        } catch (\Throwable) {
            // Selected Python authority never falls back to Laravel.
        }

        return $this->unavailable();
    }

    private function unavailable(): array
    {
        return ['ok' => false, 'status' => 503, 'error' => 'graph_ops_unavailable', 'message' => 'Graph operations are unavailable.'];
    }

    private function validResponse(string $action, array $request, array $body): bool
    {
        if ($action === 'graph/scope' || $action === 'graph/clear-admission') {
            return ($body['dataset_id'] ?? null) === ($request['dataset_id'] ?? null)
                && is_string($body['neo4j_namespace'] ?? null) && trim($body['neo4j_namespace']) !== ''
                && ($action === 'graph/scope' || (($body['ok'] ?? false) === true
                    && is_string($body['operation_id'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/', $body['operation_id'])));
        }
        if ($action === 'graph/clear-receipt') {
            return is_bool($body['ok'] ?? null);
        }
        if (($body['ok'] ?? false) !== true) {
            return false;
        }

        return match ($action) {
            'graph/overview', 'graph/node', 'graph/expand' => is_array($body['nodes'] ?? null) && is_array($body['edges'] ?? null),
            'graph/search', 'graph/semantic-search' => is_array($body['results'] ?? null),
            'graph/clear' => is_int($body['deleted_nodes'] ?? null) && is_int($body['deleted_relationships'] ?? null),
            'stats' => is_array($body['qdrant'] ?? null) && is_array($body['neo4j'] ?? null),
            'config' => ($body['authority'] ?? null) === 'python' && is_array($body['models'] ?? null),
            default => false,
        };
    }
}
