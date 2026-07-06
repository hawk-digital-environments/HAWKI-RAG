<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\Document;
use App\Models\SpecV2\Application;
use App\Services\Authorization\Repositories\AuthorizationIdentityRepository;
use App\Services\Authorization\Repositories\PermissionEventRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;

#[Singleton]
readonly class GatewaySearchFilterService
{
    private const NO_MATCH_DOCUMENT_ID = '__rawki_no_match__';

    public function __construct(
        private ConfigRepository $config,
        private AuthorizationIdentityRepository $identities,
        private PermissionEventRepository $permissionEvents,
    ) {}

    /**
     * @param array<string, mixed> $clientFilters
     * @return array<string, mixed>
     */
    public function build(array $clientFilters, ApiActor $actor, ?string $requestedUserIdentifier = null): array
    {
        $clientFilter = $this->normalizeClientFilters($clientFilters);

        if ($this->scopeIsGlobal($actor) && ! $this->authorizationApplies($actor)) {
            return $clientFilter;
        }

        $documentFilter = $this->documentAllowFilter($this->scopedDocumentIds($actor, $requestedUserIdentifier));

        if ($clientFilter === []) {
            return $documentFilter;
        }

        return ['must' => [$documentFilter, $clientFilter]];
    }

    private function scopeIsGlobal(ApiActor $actor): bool
    {
        return $actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED);
    }

    private function authorizationApplies(ApiActor $actor): bool
    {
        return (bool) $this->config->get('authz.enabled', false)
            && ! $actor->hasApplicationPermission(Application::PERMISSION_READS_PROTECTED);
    }

    /**
     * @return list<string>
     */
    private function scopedDocumentIds(ApiActor $actor, ?string $requestedUserIdentifier): array
    {
        $scope = $this->scopedDocumentsQuery($actor);

        if (! $this->authorizationApplies($actor)) {
            return $this->pluckDocumentIds($scope);
        }

        $public = $this->pluckDocumentIds(
            (clone $scope)->where(function (Builder $query): void {
                $query->where('datasets.protected', false)
                    ->orWhereNull('datasets.protected');
            })
        );

        $subjects = $this->authorizationSubjects($actor, $requestedUserIdentifier);
        if ($subjects === []) {
            return $public;
        }

        $accessibleProtectedIds = $this->permissionEvents->accessibleDocumentIdsForSubjects($subjects);
        if ($accessibleProtectedIds === []) {
            return $public;
        }

        $protected = $this->pluckDocumentIds(
            (clone $scope)
                ->where('datasets.protected', true)
                ->whereIn('documents.id', $accessibleProtectedIds)
        );

        return array_values(array_unique([...$public, ...$protected]));
    }

    private function scopedDocumentsQuery(ApiActor $actor): Builder
    {
        $query = Document::query()
            ->select('documents.id')
            ->distinct()
            ->join('datasets', 'datasets.dataset_id', '=', 'documents.dataset_id');

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED)) {
            return $query;
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_ALL_APPS)) {
            return $query->where('datasets.tenant_id', $actor->tenantId());
        }

        return $query->where('datasets.owner_application_id', $actor->applicationId());
    }

    /**
     * @return list<array{provider: string, user_id: string}>
     */
    private function authorizationSubjects(ApiActor $actor, ?string $requestedUserIdentifier): array
    {
        if ($actor->isUser()) {
            $identity = $actor->identity();
            if ($identity === null) {
                return [];
            }

            return $this->normalizeSubjects([[
                'provider' => $identity->provider,
                'user_id' => $identity->external_user_id,
            ]]);
        }

        $identifier = $this->stringValue($requestedUserIdentifier);
        if ($identifier === null) {
            return [];
        }

        $tenantIds = $actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED)
            ? null
            : [$actor->tenantId()];

        $identities = $this->identities->findAllByIdentifiers([$identifier], $tenantIds);

        return $this->normalizeSubjects(
            $identities->map(fn ($identity): array => [
                'provider' => $identity->provider,
                'user_id' => $identity->external_user_id,
            ])->all(),
        );
    }

    /**
     * @param list<array{provider: mixed, user_id: mixed}> $subjects
     * @return list<array{provider: string, user_id: string}>
     */
    private function normalizeSubjects(array $subjects): array
    {
        $normalized = [];

        foreach ($subjects as $subject) {
            $provider = $this->stringValue($subject['provider'] ?? null);
            $userId = $this->stringValue($subject['user_id'] ?? null);
            if ($provider === null || $userId === null) {
                continue;
            }

            $key = $provider.'|'.$userId;
            $normalized[$key] = [
                'provider' => $provider,
                'user_id' => $userId,
            ];
        }

        return array_values($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeClientFilters(array $filters): array
    {
        if ($filters === []) {
            return [];
        }

        if ($this->looksLikeFilterBody($filters)) {
            return $this->normalizeFilterBody($filters);
        }

        $must = [];

        foreach ($filters as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $field = $this->normalizeFilterKey($key);
            if (is_array($value)) {
                $matches = [];
                foreach ($value as $candidate) {
                    $normalizedValue = $this->normalizeFilterValue($candidate);
                    if ($normalizedValue !== null) {
                        $matches[] = ['key' => $field, 'match' => ['value' => $normalizedValue]];
                    }
                }

                if ($matches === []) {
                    continue;
                }

                $must[] = count($matches) === 1 ? $matches[0] : ['should' => $matches];
                continue;
            }

            $normalizedValue = $this->normalizeFilterValue($value);
            if ($normalizedValue === null) {
                continue;
            }

            $must[] = ['key' => $field, 'match' => ['value' => $normalizedValue]];
        }

        return $must === [] ? [] : ['must' => $must];
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    private function normalizeFilterBody(array $filter): array
    {
        $normalized = [];

        foreach (['must', 'should', 'must_not'] as $clause) {
            $items = $filter[$clause] ?? null;
            if (! is_array($items)) {
                continue;
            }

            $normalizedItems = [];
            foreach ($items as $item) {
                $normalizedItem = $this->normalizeFilterNode($item);
                if ($normalizedItem !== null) {
                    $normalizedItems[] = $normalizedItem;
                }
            }

            if ($normalizedItems !== []) {
                $normalized[$clause] = $normalizedItems;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeFilterNode(mixed $node): ?array
    {
        if (! is_array($node)) {
            return null;
        }

        if ($this->looksLikeFilterBody($node)) {
            return $this->normalizeFilterBody($node);
        }

        $key = $this->stringValue($node['key'] ?? null);
        if ($key === null || ! is_array($node['match'] ?? null)) {
            return null;
        }

        return [
            'key' => $this->normalizeFilterKey($key),
            'match' => $node['match'],
        ];
    }

    /**
     * @param list<string> $documentIds
     * @return array<string, mixed>
     */
    private function documentAllowFilter(array $documentIds): array
    {
        $documentIds = array_values(array_unique(array_filter(array_map(
            fn (string $id): string => trim($id),
            $documentIds,
        ))));

        if ($documentIds === []) {
            return [
                'must' => [[
                    'key' => 'doc_id',
                    'match' => ['value' => self::NO_MATCH_DOCUMENT_ID],
                ]],
            ];
        }

        $matches = array_map(
            static fn (string $documentId): array => [
                'key' => 'doc_id',
                'match' => ['value' => $documentId],
            ],
            $documentIds,
        );

        return count($matches) === 1
            ? ['must' => [$matches[0]]]
            : ['should' => $matches];
    }

    private function looksLikeFilterBody(array $filter): bool
    {
        return array_intersect(['must', 'should', 'must_not'], array_keys($filter)) !== [];
    }

    private function normalizeFilterKey(string $key): string
    {
        return $key === 'document_id' ? 'doc_id' : $key;
    }

    private function normalizeFilterValue(mixed $value): string|int|float|bool|null
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function pluckDocumentIds(Builder $query): array
    {
        return $query->pluck('documents.id')
            ->filter(fn (mixed $id): bool => is_string($id) && trim($id) !== '')
            ->values()
            ->all();
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
