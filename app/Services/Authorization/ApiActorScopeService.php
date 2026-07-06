<?php
declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\Dataset;
use App\Models\SpecV2\Application;
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
        private ApplicationScopeResolver $documents,
    ) {}

    public function currentActor(): ?ApiActor
    {
        $principal = $this->auth->guard()->user();
        if ($principal === null) {
            return null;
        }

        try {
            return $this->actors->resolvePrincipal($principal);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{tenant_id?: string, owner_application_id?: string}
     */
    public function currentDatasetFilters(): array
    {
        $actor = $this->currentActor();
        if (! $actor instanceof ApiActor) {
            return [];
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED)) {
            return [];
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_ALL_APPS)) {
            return ['tenant_id' => $actor->tenantId()];
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS)) {
            return ['owner_application_id' => $actor->applicationId()];
        }

        return ['owner_application_id' => '__rawki_no_match__'];
    }

    public function currentCanReadDataset(Dataset $dataset): bool
    {
        $actor = $this->currentActor();
        if (! $actor instanceof ApiActor) {
            return false;
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_FEDERATED)) {
            return true;
        }

        if ($actor->hasApplicationPermission(Application::PERMISSION_READS_ALL_APPS)) {
            return (string) ($dataset->tenant_id ?: $this->defaultTenantId()) === $actor->tenantId();
        }

        return $actor->hasApplicationPermission(Application::PERMISSION_READS)
            && (string) ($dataset->owner_application_id ?: $this->defaultApplicationId()) === $actor->applicationId();
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
