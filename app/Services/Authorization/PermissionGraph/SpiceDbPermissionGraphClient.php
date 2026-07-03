<?php

declare(strict_types=1);

namespace App\Services\Authorization\PermissionGraph;

use App\Services\Authorization\Contracts\PermissionGraphClient;
use App\Services\Authorization\Values\PermissionGraphRelationship;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class SpiceDbPermissionGraphClient implements PermissionGraphClient
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
        private PermissionGraphRelationshipFactory $relationships,
    ) {}

    public function backendId(): string
    {
        return 'spicedb';
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
            'updates' => array_map(fn (PermissionGraphRelationship $relationship): array => [
                'operation' => 'OPERATION_TOUCH',
                'relationship' => $this->relationshipPayload($relationship),
            ], $relationships),
        ];

        return $this->post('/v1/relationships/write', $payload);
    }

    public function batchCheckDocuments(string $provider, string $externalUserId, array $documentIds): array
    {
        $documentIds = array_values(array_unique(array_filter($documentIds)));
        if ($documentIds === []) {
            return [];
        }

        $subjectId = $this->relationships->scopedId($provider, $externalUserId);
        $payload = [
            'consistency' => $this->consistencyPayload(),
            'items' => array_map(fn (string $documentId): array => [
                'resource' => [
                    'object_type' => 'document',
                    'object_id' => $this->relationships->safe($documentId),
                ],
                'permission' => 'viewer',
                'subject' => [
                    'object' => [
                        'object_type' => 'user',
                        'object_id' => $subjectId,
                    ],
                ],
            ], $documentIds),
        ];

        $response = $this->post('/v1/permissions/checkbulk', $payload);
        $pairs = is_array($response['pairs'] ?? null) ? $response['pairs'] : [];
        $allowed = [];
        foreach ($documentIds as $index => $documentId) {
            $pair = is_array($pairs[$index] ?? null) ? $pairs[$index] : [];
            $item = is_array($pair['item'] ?? null) ? $pair['item'] : [];
            $allowed[$documentId] = $this->hasPermission($item['permissionship'] ?? null);
        }

        return $allowed;
    }

    private function relationshipPayload(PermissionGraphRelationship $relationship): array
    {
        $subject = [
            'object' => [
                'object_type' => $relationship->subjectType,
                'object_id' => $relationship->subjectId,
            ],
        ];
        if ($relationship->subjectRelation !== null) {
            $subject['optional_relation'] = $relationship->subjectRelation;
        }

        return [
            'resource' => [
                'object_type' => $relationship->resourceType,
                'object_id' => $relationship->resourceId,
            ],
            'relation' => $relationship->relation,
            'subject' => $subject,
        ];
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
        $token = $this->string($this->config->get('authz.graph.spicedb.preshared_key'));
        if ($token !== null) {
            $request = $request->withToken($token);
        }

        $response = $request->post(rtrim((string) $this->config->get('authz.graph.spicedb.api_url'), '/').$path, $payload);

        return ($response->json() ?? []) + [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'backend' => $this->backendId(),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function consistencyPayload(): array
    {
        return match ((string) $this->config->get('authz.graph.spicedb.consistency', 'minimize_latency')) {
            'fully_consistent' => ['fully_consistent' => true],
            default => ['minimize_latency' => true],
        };
    }

    private function hasPermission(mixed $permissionship): bool
    {
        if (is_int($permissionship)) {
            return $permissionship === 2;
        }

        return is_string($permissionship) && str_ends_with($permissionship, 'HAS_PERMISSION');
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
