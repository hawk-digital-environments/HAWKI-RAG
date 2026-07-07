<?php
declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\SpecV2\Application;
use App\Models\SpecV2\Corpus;
use App\Models\SpecV2\Group;
use App\Models\SpecV2\Heap;
use App\Models\SpecV2\Tenant;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class ApiActorScopeService
{
    public function __construct(
        private AuthFactory $auth,
        private ConfigRepository $config,
        private ApiActorResolver $actors,
        private ApplicationTokenService $tokens,
        private ApplicationReadPolicy $policy,
        private ApplicationScopeResolver $documents,
    ) {}

    public function currentActor(): ?ApiActor
    {
        $application = $this->tokens->authenticate(request()->bearerToken());
        if ($application instanceof Application) {
            return ApiActor::forApplication($application);
        }

        foreach (['sanctum', 'oidc'] as $guard) {
            $principal = $this->auth->guard($guard)->user();
            if ($principal === null) {
                continue;
            }

            try {
                return $this->actors->resolvePrincipal($principal);
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @return array{tenant_id?: string, owner_application_id?: string}
     */
    public function currentHeapFilters(): array
    {
        $actor = $this->currentActor();
        if (! $actor instanceof ApiActor) {
            return [];
        }

        return $this->policy->heapFilters($actor);
    }

    public function currentCanReadHeap(Heap $heap): bool
    {
        $actor = $this->currentActor();
        if (! $actor instanceof ApiActor) {
            return false;
        }

        return $this->policy->canReadHeap($actor, $heap);
    }

    /**
     * @return array{document_ids?: list<string>}
     */
    public function currentDocumentFilters(?string $requestedUserIdentifier = null): array
    {
        $actor = $this->currentActor();
        if (! $actor instanceof ApiActor) {
            return ['document_ids' => []];
        }

        $scope = $this->documents->resolve($actor, $requestedUserIdentifier);
        if ($scope->unrestricted) {
            return [];
        }

        return ['document_ids' => $scope->documentIds ?? []];
    }

    public function currentCanReadDocument(string $documentId, ?string $requestedUserIdentifier = null): bool
    {
        $actor = $this->currentActor();
        if (! $actor instanceof ApiActor) {
            return false;
        }

        $scope = $this->documents->resolve($actor, $requestedUserIdentifier);

        return $scope->unrestricted
            || in_array($documentId, $scope->documentIds ?? [], true);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function currentGroupFilters(): array
    {
        $actor = $this->currentActor();
        if (! $actor instanceof ApiActor) {
            return ['owner_application_id' => '__rawki_no_match__'];
        }

        return $this->policy->groupFilters($actor);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function currentApplicationFilters(): array
    {
        $actor = $this->currentActor();
        if (! $actor instanceof ApiActor) {
            return ['id' => '__rawki_no_match__'];
        }

        return $this->policy->applicationFilters($actor);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function currentTenantFilters(): array
    {
        $actor = $this->currentActor();
        if (! $actor instanceof ApiActor) {
            return ['id' => '__rawki_no_match__'];
        }

        return $this->policy->tenantFilters($actor);
    }

    public function currentCanReadGroup(Group $group): bool
    {
        $actor = $this->currentActor();
        if (! $actor instanceof ApiActor) {
            return false;
        }

        return $this->policy->canReadGroup($actor, $group);
    }

    public function currentCanReadApplication(Application $application): bool
    {
        $actor = $this->currentActor();
        if (! $actor instanceof ApiActor) {
            return false;
        }

        return $this->policy->canReadApplication($actor, $application);
    }

    public function currentCanReadTenant(Tenant $tenant): bool
    {
        $actor = $this->currentActor();
        if (! $actor instanceof ApiActor) {
            return false;
        }

        return $this->policy->canReadTenant($actor, $tenant);
    }

    /**
     * @return list<string>|null
     */
    public function currentCorpusIds(): ?array
    {
        $actor = $this->currentActor();
        if (! $actor instanceof ApiActor) {
            return [];
        }

        $scope = $this->policy->documentScope($actor);

        return $scope->unrestricted ? null : ($scope->documentIds ?? []);
    }

    public function currentCanReadCorpus(Corpus $corpus): bool
    {
        $documentIds = $this->currentCorpusIds();
        if ($documentIds === null) {
            return true;
        }

        if ($documentIds === []) {
            return false;
        }

        return $corpus->documents()
            ->whereIn('documents.id', $documentIds)
            ->exists();
    }

    /**
     * @return array{tenant_id: string, owner_application_id: string}
     */
    public function currentOwnershipDefaults(): array
    {
        $actor = $this->currentActor();
        if ($actor instanceof ApiActor) {
            return [
                'tenant_id' => $actor->tenantId(),
                'owner_application_id' => $actor->applicationId(),
            ];
        }

        return [
            'tenant_id' => 'default',
            'owner_application_id' => 'rawki-default',
        ];
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
