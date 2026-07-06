<?php
declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\SpecV2\Application;
use App\Services\SpecV2\Repositories\ApplicationRepository;
use App\Services\SpecV2\Repositories\TenantRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class IdentityContextResolver
{
    public function __construct(
        private ConfigRepository $config,
        private TenantRepository $tenants,
        private ApplicationRepository $applications,
    ) {}

    public function tenantIdFromClaims(array $claims): string
    {
        foreach ($this->tenantClaimKeys() as $key) {
            $value = $claims[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return $this->defaultTenantId();
    }

    public function applicationIdFromClaims(array $claims): ?string
    {
        foreach ($this->applicationClaimKeys() as $key) {
            $value = $claims[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    public function ensureApplication(string $tenantId, ?string $requestedApplicationId): string
    {
        $this->ensureTenant($tenantId);

        $applicationId = $this->resolvedApplicationId($tenantId, $requestedApplicationId);
        if ($this->applications->findById($applicationId) instanceof Application) {
            return $applicationId;
        }

        $this->applications->create([
            'id' => $applicationId,
            'tenant_id' => $tenantId,
            'name' => $requestedApplicationId === null
                ? $this->defaultApplicationName()
                : $this->displayName($requestedApplicationId),
            'description' => 'Auto-provisioned application context for authorization identity mapping.',
            'permissions' => [Application::PERMISSION_READS],
            'token_hash' => null,
            'metadata_json' => [
                'source' => 'auth-bridge',
                'requested_application_id' => $requestedApplicationId,
            ],
        ]);

        return $applicationId;
    }

    public function defaultTenantId(): string
    {
        return (string) $this->config->get('authz.identity_bridge.default_tenant_id', 'default');
    }

    private function ensureTenant(string $tenantId): void
    {
        if ($this->tenants->findById($tenantId) !== null) {
            return;
        }

        $this->tenants->create([
            'id' => $tenantId,
            'name' => $tenantId === $this->defaultTenantId()
                ? $this->defaultTenantName()
                : $this->displayName($tenantId),
            'metadata_json' => ['source' => 'auth-bridge'],
        ]);
    }

    private function resolvedApplicationId(string $tenantId, ?string $requestedApplicationId): string
    {
        $baseId = $requestedApplicationId ?? $this->defaultApplicationId();
        $existing = $this->applications->findById($baseId);

        if ($requestedApplicationId === null && $tenantId !== $this->defaultTenantId()) {
            return $tenantId.':'.$baseId;
        }

        if ($existing instanceof Application && $existing->tenant_id !== $tenantId) {
            return $tenantId.':'.$baseId;
        }

        return $baseId;
    }

    private function defaultTenantName(): string
    {
        return (string) $this->config->get('authz.identity_bridge.default_tenant_name', 'Default Tenant');
    }

    private function defaultApplicationId(): string
    {
        return (string) $this->config->get('authz.identity_bridge.default_application_id', 'rawki-default');
    }

    private function defaultApplicationName(): string
    {
        return (string) $this->config->get('authz.identity_bridge.default_application_name', 'RAWKI Default');
    }

    /**
     * @return list<string>
     */
    private function tenantClaimKeys(): array
    {
        $keys = $this->config->get('authz.identity_bridge.tenant_claim_keys', []);

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    /**
     * @return list<string>
     */
    private function applicationClaimKeys(): array
    {
        $keys = $this->config->get('authz.identity_bridge.application_claim_keys', []);

        return is_array($keys) ? array_values(array_filter($keys, 'is_string')) : [];
    }

    private function displayName(string $value): string
    {
        return ucwords(str_replace([':', '-', '_'], ' ', $value));
    }
}
