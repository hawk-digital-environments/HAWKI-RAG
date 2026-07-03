<?php

declare(strict_types=1);

namespace App\Services\Authorization\PermissionGraph;

use App\Services\Authorization\Contracts\PermissionGraphClient;
use App\Services\Authorization\Values\PermissionGraphRelationship;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class OpenFgaPermissionGraphClient implements PermissionGraphClient
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
        private PermissionGraphRelationshipFactory $relationships,
    ) {}

    public function backendId(): string
    {
        return 'openfga';
    }

    /**
     * @param list<PermissionGraphRelationship> $relationships
     * @return array<string, mixed>
     */
    public function writeRelationships(array $relationships): array
    {
        $relationships = $this->uniqueRelationships($relationships);
        if ($relationships === []) {
            return ['ok' => true, 'written' => 0, 'backend' => $this->backendId()];
        }

        $payload = [
            'writes' => [
                'tuple_keys' => array_map(fn (PermissionGraphRelationship $relationship): array => [
                    'user' => $this->objectString($relationship->subjectType, $relationship->subjectId),
                    'relation' => $relationship->relation,
                    'object' => $this->objectString($relationship->resourceType, $relationship->resourceId),
                ], $relationships),
            ],
        ];

        return $this->post('/stores/'.$this->storeId().'/write', $payload);
    }

    public function batchCheckDocuments(string $provider, string $externalUserId, array $documentIds): array
    {
        $documentIds = array_values(array_unique(array_filter($documentIds)));
        if ($documentIds === []) {
            return [];
        }

        $userObject = $this->objectString('user', $this->relationships->scopedId($provider, $externalUserId));
        $checks = array_map(fn (string $documentId): array => [
            'tuple_key' => [
                'user' => $userObject,
                'relation' => 'viewer',
                'object' => $this->objectString('document', $this->relationships->safe($documentId)),
            ],
            'contextual_tuples' => ['tuple_keys' => []],
        ], $documentIds);

        $response = $this->post('/stores/'.$this->storeId().'/batch-check', [
            'authorization_model_id' => $this->authorizationModelId(),
            'checks' => $checks,
        ]);

        $allowed = [];
        foreach (($response['result'] ?? []) as $index => $result) {
            $documentId = $documentIds[$index] ?? null;
            if ($documentId !== null) {
                $allowed[$documentId] = (bool) ($result['allowed'] ?? false);
            }
        }

        return $allowed;
    }

    /**
     * @param list<PermissionGraphRelationship> $relationships
     * @return list<PermissionGraphRelationship>
     */
    private function uniqueRelationships(array $relationships): array
    {
        $unique = [];
        foreach ($relationships as $relationship) {
            $unique[$relationship->key()] = $relationship;
        }

        return array_values($unique);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(string $path, array $payload): array
    {
        $request = $this->http->timeout((int) $this->config->get('authz.graph.timeout_seconds', 5));
        $token = $this->string($this->config->get('authz.graph.openfga.token'));
        if ($token !== null) {
            $request = $request->withToken($token);
        }

        $response = $request->post(rtrim((string) $this->config->get('authz.graph.openfga.api_url'), '/').$path, $payload);

        return ($response->json() ?? []) + [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'backend' => $this->backendId(),
        ];
    }

    private function objectString(string $type, string $id): string
    {
        return $type.':'.$id;
    }

    private function storeId(): string
    {
        return (string) $this->config->get('authz.graph.openfga.store_id', '');
    }

    private function authorizationModelId(): ?string
    {
        return $this->string($this->config->get('authz.graph.openfga.authorization_model_id'));
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
