<?php
declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\SpecV2\Application;
use App\Models\SpecV2\Group;
use App\Models\SpecV2\Heap;
use App\Models\SpecV2\Tenant;
use App\Services\Authorization\Values\ApplicationDocumentScope;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class ApplicationReadPolicy
{
    private const NO_MATCH = '__rawki_no_match__';

    public function __construct(
        private ConfigRepository $config,
        private AuthorizationModeService $mode,
        private ApplicationScopeResolver $documents,
    ) {}

    public function documentScope(ApiActor $actor, ?string $requestedUserIdentifier = null): ApplicationDocumentScope
    {
        return $this->documents->resolve($actor, $requestedUserIdentifier);
    }

    /**
     * @return array{id?: string}
     */
    public function tenantFilters(ApiActor $actor): array
    {
        if (! $this->canReadAny($actor)) {
            return ['id' => self::NO_MATCH];
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED)) {
            return [];
        }

        return ['id' => $actor->tenantId()];
    }

    /**
     * @return array{id?: string, tenant_id?: string}
     */
    public function applicationFilters(ApiActor $actor): array
    {
        if (! $this->canReadAny($actor)) {
            return ['id' => self::NO_MATCH];
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED)) {
            return [];
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_ALL_APPS)) {
            return ['tenant_id' => $actor->tenantId()];
        }

        return ['id' => $actor->applicationId()];
    }

    /**
     * @return array<array-key, mixed>
     */
    public function heapFilters(ApiActor $actor): array
    {
        $filters = $this->baseResourceFilters($actor);

        if ($filters === [] && $actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED)) {
            return $this->protectedResourceFilters($actor);
        }

        return array_replace($filters, $this->protectedResourceFilters($actor));
    }

    /**
     * @return array<array-key, mixed>
     */
    public function groupFilters(ApiActor $actor): array
    {
        return $this->baseResourceFilters($actor);
    }

    public function canReadTenant(ApiActor $actor, Tenant $tenant): bool
    {
        if (! $this->canReadAny($actor)) {
            return false;
        }

        return $actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED)
            || (string) $tenant->id === $actor->tenantId();
    }

    public function canReadApplication(ApiActor $actor, Application $application): bool
    {
        if (! $this->canReadAny($actor)) {
            return false;
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED)) {
            return true;
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_ALL_APPS)) {
            return (string) $application->tenant_id === $actor->tenantId();
        }

        return (string) $application->id === $actor->applicationId();
    }

    public function canReadGroup(ApiActor $actor, Group $group): bool
    {
        return $this->matchesBaseScope(
            $actor,
            (string) $group->tenant_id,
            (string) $group->owner_application_id,
        );
    }

    public function canReadHeap(ApiActor $actor, Heap $heap): bool
    {
        if (! $this->matchesBaseScope(
            $actor,
            (string) ($heap->tenant_id ?: $this->defaultTenantId()),
            (string) ($heap->owner_application_id ?: $this->defaultApplicationId()),
        )) {
            return false;
        }

        return ! $this->authorizationAppliesToProtectedResources($actor)
            || ! (bool) $heap->protected;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function baseResourceFilters(ApiActor $actor): array
    {
        if (! $this->canReadAny($actor)) {
            return ['owner_application_id' => self::NO_MATCH];
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED)) {
            return [];
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_ALL_APPS)) {
            return ['tenant_id' => $actor->tenantId()];
        }

        return ['owner_application_id' => $actor->applicationId()];
    }

    /**
     * @return array{protected?: bool}
     */
    private function protectedResourceFilters(ApiActor $actor): array
    {
        return $this->authorizationAppliesToProtectedResources($actor)
            ? ['protected' => false]
            : [];
    }

    private function matchesBaseScope(ApiActor $actor, string $tenantId, string $applicationId): bool
    {
        if (! $this->canReadAny($actor)) {
            return false;
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED)) {
            return true;
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_ALL_APPS)) {
            return $tenantId === $actor->tenantId();
        }

        return $applicationId === $actor->applicationId();
    }

    private function canReadAny(ApiActor $actor): bool
    {
        return $actor->hasApplicationPermission(Application::PERMISSION_READS)
            || $actor->hasApplicationPermission(Application::PERMISSION_READS_ALL_APPS)
            || $actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED);
    }

    private function authorizationAppliesToProtectedResources(ApiActor $actor): bool
    {
        return $this->mode->enabled()
            && ! $actor->hasApplicationPermission(Application::PERMISSION_READS_PROTECTED);
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
