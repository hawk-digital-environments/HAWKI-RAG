<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\Document;
use App\Models\SpecV2\Application;
use App\Services\Authorization\Repositories\GrantAccessRepository;
use App\Services\Authorization\Repositories\UserIdentityRepository;
use App\Services\Authorization\Values\ApplicationDocumentScope;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Eloquent\Builder;

#[Singleton]
readonly class ApplicationScopeResolver
{
    public function __construct(
        private ConfigRepository $config,
        private UserIdentityRepository $identities,
        private GrantAccessRepository $grants,
    ) {}

    public function resolve(ApiActor $actor, ?string $requestedUserIdentifier = null): ApplicationDocumentScope
    {
        if (! $this->canReadAnyDocuments($actor)) {
            return ApplicationDocumentScope::none();
        }

        if ($this->isGloballyReadable($actor) && ! $this->authorizationApplies($actor)) {
            return ApplicationDocumentScope::unrestricted();
        }

        $baseFilters = $this->baseRepositoryFilters($actor);
        $scope = $this->baseScopedDocumentsQuery($actor);

        if (! $this->authorizationApplies($actor)) {
            return ApplicationDocumentScope::constrained($baseFilters, $this->pluckDocumentIds($scope));
        }

        $public = $this->pluckDocumentIds(
            (clone $scope)->where(function (Builder $query): void {
                $query->where('heaps.protected', false)
                    ->orWhereNull('heaps.protected');
            }),
        );

        $internalUserIds = $this->authorizedInternalUserIds($actor, $requestedUserIdentifier);
        if ($internalUserIds === []) {
            return ApplicationDocumentScope::constrained(['document_ids' => $public], $public);
        }

        $accessibleProtectedIds = $this->grants->accessibleDocumentIdsForInternalUsers($internalUserIds);
        if ($accessibleProtectedIds === []) {
            return ApplicationDocumentScope::constrained(['document_ids' => $public], $public);
        }

        $protected = $this->pluckDocumentIds(
            (clone $scope)
                ->where('heaps.protected', true)
                ->whereIn('documents.id', $accessibleProtectedIds),
        );

        $documentIds = array_values(array_unique([...$public, ...$protected]));

        return ApplicationDocumentScope::constrained(['document_ids' => $documentIds], $documentIds);
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
        return (bool) $this->config->get('authz.enabled', false)
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
        $query = Document::query()
            ->select('documents.id')
            ->distinct()
            ->join('datasets as heaps', 'heaps.dataset_id', '=', 'documents.dataset_id');

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

        $identities = $this->identities->findAllByIdentifiers([$identifier], $tenantIds);

        return $identities
            ->pluck('internal_user_id')
            ->filter(fn (mixed $internalUserId): bool => is_string($internalUserId) && trim($internalUserId) !== '')
            ->map(fn (string $internalUserId): string => trim($internalUserId))
            ->unique()
            ->values()
            ->all();
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
