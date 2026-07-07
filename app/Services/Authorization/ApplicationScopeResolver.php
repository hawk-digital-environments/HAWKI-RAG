<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\Document;
use App\Models\SpecV2\Application;
use App\Models\SpecV2\Heap;
use App\Services\Authorization\Repositories\GrantAccessRepository;
use App\Services\Authorization\Repositories\UserIdentityRepository;
use App\Services\Authorization\Values\ApplicationDocumentScope;
use App\Services\Rag\Values\FilterExpression;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;

#[Singleton]
readonly class ApplicationScopeResolver
{
    private const NO_MATCH_DOCUMENT_ID = '__rawki_no_match__';

    public function __construct(
        private ConfigRepository $config,
        private AuthorizationModeService $mode,
        private UserIdentityRepository $identities,
        private GrantAccessRepository $grants,
    ) {}

    public function resolve(ApiActor $actor, ?string $requestedUserIdentifier = null): ApplicationDocumentScope
    {
        if (! $this->canReadAnyDocuments($actor)) {
            return ApplicationDocumentScope::none();
        }

        $baseHeapIds = $this->baseScopedHeapIds($actor);

        if ($this->isGloballyReadable($actor) && ! $this->authorizationApplies($actor)) {
            return ApplicationDocumentScope::unrestricted();
        }

        $baseFilters = $this->baseRepositoryFilters($actor);
        $scope = $this->baseScopedDocumentsQuery($actor);
        $baseSearchExpression = $this->baseSearchExpression($actor, $baseHeapIds);

        if (! $this->authorizationApplies($actor)) {
            return ApplicationDocumentScope::constrained(
                $baseFilters,
                $this->pluckDocumentIds($scope),
                $baseSearchExpression,
            );
        }

        $public = $this->pluckDocumentIds(
            (clone $scope)->where(function (Builder $query): void {
                $query->where('heaps.protected', false)
                    ->orWhereNull('heaps.protected');
            }),
        );
        $publicSearchExpression = $this->and([
            $baseSearchExpression,
            FilterExpression::leaf('protected', false),
        ]);

        $internalUserIds = $this->authorizedInternalUserIds($actor, $requestedUserIdentifier);
        if ($internalUserIds === []) {
            return ApplicationDocumentScope::constrained(['document_ids' => $public], $public, $publicSearchExpression);
        }

        $heapGrantIds = $this->grants->heapGrantedHeapIdsForInternalUsers($internalUserIds);
        $documentGrantIds = $this->grants->documentGrantedDocumentIdsForInternalUsers($internalUserIds);
        $searchExpression = $this->or([
            $publicSearchExpression,
            ...$this->protectedSearchExpressions($baseSearchExpression, $heapGrantIds, $documentGrantIds),
        ]);

        $accessibleProtectedIds = $this->grants->accessibleDocumentIdsForInternalUsers($internalUserIds);
        if ($accessibleProtectedIds === []) {
            return ApplicationDocumentScope::constrained(['document_ids' => $public], $public, $searchExpression);
        }

        $protected = $this->pluckDocumentIds(
            (clone $scope)
                ->where('heaps.protected', true)
                ->whereIn('documents.id', $accessibleProtectedIds),
        );

        $documentIds = array_values(array_unique([...$public, ...$protected]));

        return ApplicationDocumentScope::constrained(['document_ids' => $documentIds], $documentIds, $searchExpression);
    }

    private function canReadAnyDocuments(ApiActor $actor): bool
    {
        return $actor->hasApplicationPermission(Application::PERMISSION_READS)
            || $actor->hasApplicationPermission(Application::PERMISSION_READS_ALL_APPS)
            || $actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED);
    }

    private function isGloballyReadable(ApiActor $actor): bool
    {
        return $actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED);
    }

    private function authorizationApplies(ApiActor $actor): bool
    {
        return $this->mode->enabled()
            && ! $actor->hasApplicationPermission(Application::PERMISSION_READS_PROTECTED);
    }

    /**
     * @return array<string, string>
     */
    private function baseRepositoryFilters(ApiActor $actor): array
    {
        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_ALL_APPS)) {
            return ['tenant_id' => $actor->tenantId()];
        }

        return ['owner_application_id' => $actor->applicationId()];
    }

    private function baseScopedDocumentsQuery(ApiActor $actor): Builder
    {
        $heap = new Heap();
        $document = new Document();

        $query = Document::query()
            ->select('documents.id')
            ->distinct()
            ->join($heap->getTable().' as heaps', 'heaps.'.$heap->storageKeyName(), '=', $document->getTable().'.'.$document->heapStorageColumn());

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED)) {
            return $query;
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_ALL_APPS)) {
            return $query->where(function (Builder $inner) use ($actor): void {
                $inner->where('heaps.tenant_id', $actor->tenantId());

                if ($actor->tenantId() === $this->defaultTenantId()) {
                    $inner->orWhereNull('heaps.tenant_id');
                }
            });
        }

        return $query->where(function (Builder $inner) use ($actor): void {
            $inner->where('heaps.owner_application_id', $actor->applicationId());

            if ($actor->applicationId() === $this->defaultApplicationId()) {
                $inner->orWhereNull('heaps.owner_application_id');
            }
        });
    }

    /**
     * @return list<string>|null
     */
    private function baseScopedHeapIds(ApiActor $actor): ?array
    {
        $heap = new Heap();

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED)) {
            return null;
        }

        if (! $actor->hasApplicationPermission(Application::PERMISSION_READS_ALL_APPS)) {
            return null;
        }

        return Heap::query()
            ->select($heap->storageKeyName())
            ->where(function (Builder $inner) use ($actor): void {
                $inner->where('tenant_id', $actor->tenantId());

                if ($actor->tenantId() === $this->defaultTenantId()) {
                    $inner->orWhereNull('tenant_id');
                }
            })
            ->pluck($heap->storageKeyName())
            ->filter(fn (mixed $heapId): bool => is_string($heapId) && trim($heapId) !== '')
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function pluckDocumentIds(Builder $query): array
    {
        return $query->pluck('documents.id')
            ->filter(fn (mixed $documentId): bool => is_string($documentId) && trim($documentId) !== '')
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function authorizedInternalUserIds(ApiActor $actor, ?string $requestedUserIdentifier): array
    {
        if ($actor->isUser()) {
            $identity = $actor->identity();
            return $identity !== null && is_string($identity->internal_user_id) && trim($identity->internal_user_id) !== ''
                ? [(string) $identity->internal_user_id]
                : [];
        }

        $identifier = $this->stringValue($requestedUserIdentifier);
        if ($identifier === null) {
            return [];
        }

        $tenantIds = $actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED)
            ? null
            : [$actor->tenantId()];

        $identities = $this->identities->findAllSupportedByExternalUserIds([$identifier], $tenantIds);

        return $identities
            ->pluck('internal_user_id')
            ->filter(fn (mixed $internalUserId): bool => is_string($internalUserId) && trim($internalUserId) !== '')
            ->map(fn (string $internalUserId): string => trim($internalUserId))
            ->unique()
            ->values()
            ->all();
    }

    private function baseSearchExpression(ApiActor $actor, ?array $baseHeapIds): FilterExpression
    {
        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED)) {
            return FilterExpression::empty();
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_ALL_APPS)) {
            if ($baseHeapIds === []) {
                return $this->noMatchExpression();
            }

            return FilterExpression::leaf('heap', $baseHeapIds ?? []);
        }

        return FilterExpression::leaf('owner_app', $actor->applicationId());
    }

    /**
     * @param list<string> $heapGrantIds
     * @param list<string> $documentGrantIds
     * @return list<FilterExpression>
     */
    private function protectedSearchExpressions(FilterExpression $baseSearchExpression, array $heapGrantIds, array $documentGrantIds): array
    {
        $expressions = [];

        if ($heapGrantIds !== []) {
            $expressions[] = $this->and([
                $baseSearchExpression,
                FilterExpression::leaf('protected', true),
                FilterExpression::leaf('heap', $heapGrantIds),
            ]);
        }

        if ($documentGrantIds !== []) {
            $expressions[] = $this->and([
                $baseSearchExpression,
                FilterExpression::leaf('protected', true),
                FilterExpression::leaf('document_id', $documentGrantIds),
            ]);
        }

        return $expressions;
    }

    /**
     * @param list<FilterExpression> $expressions
     */
    private function and(array $expressions): FilterExpression
    {
        $expressions = array_values(array_filter(
            $expressions,
            static fn (FilterExpression $expression): bool => ! $expression->isEmpty(),
        ));

        if ($expressions === []) {
            return FilterExpression::empty();
        }

        return count($expressions) === 1
            ? $expressions[0]
            : FilterExpression::group('AND', $expressions);
    }

    /**
     * @param list<FilterExpression> $expressions
     */
    private function or(array $expressions): FilterExpression
    {
        $expressions = array_values(array_filter(
            $expressions,
            static fn (FilterExpression $expression): bool => ! $expression->isEmpty(),
        ));

        if ($expressions === []) {
            return $this->noMatchExpression();
        }

        return count($expressions) === 1
            ? $expressions[0]
            : FilterExpression::group('OR', $expressions);
    }

    private function noMatchExpression(): FilterExpression
    {
        return FilterExpression::leaf('document_id', self::NO_MATCH_DOCUMENT_ID);
    }

    private function stringValue(?string $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function defaultTenantId(): string
    {
        return (string) $this->config->get('authz.identity_bridge.default_tenant_id', 'default');
    }

    private function defaultApplicationId(): string
    {
        return (string) $this->config->get('authz.identity_bridge.default_application_id', 'rawki-default');
    }
}
