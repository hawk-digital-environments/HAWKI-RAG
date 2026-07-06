<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\SpecV2\Application;
use App\Models\UserIdentity;
use App\Models\User;
use App\Services\Authorization\Repositories\UserIdentityRepository;
use App\Services\Authorization\Values\ResolvedUserIdentity;
use App\Services\SpecV2\Repositories\ApplicationRepository;
use App\Services\SpecV2\Repositories\InternalUserRepository;
use App\Services\SpecV2\Repositories\TenantRepository;
use App\Services\SpecV2\Values\GroupMemberAssignment;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class IdentityProvisioningService
{
    public function __construct(
        private ConfigRepository $config,
        private UserIdentityRepository $identities,
        private TenantRepository $tenants,
        private ApplicationRepository $applications,
        private InternalUserRepository $internalUsers,
    ) {}

    public function actorForUser(?User $user): ?UserIdentity
    {
        if (! $user instanceof User) {
            return null;
        }

        $identity = $this->identities->findByUser($user);
        if ($identity instanceof UserIdentity) {
            return $this->hydrateIdentityContext($identity);
        }

        $tenantId = $this->defaultTenantId();
        $applicationId = $this->ensureApplication($tenantId, null);
        $internalUserId = $this->ensureInternalUser($tenantId, null, ['source' => 'local-api']);

        return $this->identities->upsertLocalUser($user, $tenantId, $applicationId, $internalUserId);
    }

    public function upsertResolvedIdentity(ResolvedUserIdentity $identity, ?User $user = null): UserIdentity
    {
        $tenantId = $this->tenantIdFromClaims($identity->claims);
        $existing = $this->identities->findByIssuerAndSubject($identity->issuer, $identity->subject);
        $candidate = $this->identities->findByTenantAndIdentifiers($tenantId, [
            $identity->externalUserId,
            $identity->email,
            $identity->username,
        ]);

        $applicationId = $this->ensureApplication($tenantId, $this->applicationClaimFromClaims($identity->claims));
        $internalUserId = $this->ensureInternalUser(
            $tenantId,
            $existing?->internal_user_id ?? $candidate?->internal_user_id,
            [
                'source' => 'oidc',
                'issuer' => $identity->issuer,
                'provider' => $identity->provider,
            ],
        );

        return $this->identities->upsertFromResolved($identity, [
            'tenant_id' => $tenantId,
            'application_id' => $applicationId,
            'internal_user_id' => $internalUserId,
        ], $user);
    }

    /**
     * @param list<string> $identifiers
     * @return list<GroupMemberAssignment>
     */
    public function groupMemberAssignments(string $tenantId, string $applicationId, array $identifiers): array
    {
        $resolvedApplicationId = $this->ensureApplication($tenantId, $applicationId);
        $assignments = [];

        foreach ($identifiers as $identifier) {
            $identity = $this->identities->findByTenantAndIdentifiers($tenantId, [$identifier]);

            if ($identity instanceof UserIdentity) {
                $identity = $this->hydrateIdentityContext($identity, $tenantId, $resolvedApplicationId);
            } else {
                $internalUserId = $this->ensureInternalUser($tenantId, null, [
                    'source' => 'group-member',
                    'application_id' => $resolvedApplicationId,
                ]);
                $identity = $this->identities->upsertExternalIdentifier(
                    $tenantId,
                    $resolvedApplicationId,
                    $internalUserId,
                    $identifier,
                );
            }

            $assignments[] = new GroupMemberAssignment($identifier, (string) $identity->internal_user_id);
        }

        return $assignments;
    }

    private function hydrateIdentityContext(
        UserIdentity $identity,
        ?string $preferredTenantId = null,
        ?string $preferredApplicationId = null,
    ): UserIdentity {
        $tenantId = $identity->tenant_id
            ?? $preferredTenantId
            ?? $this->tenantIdFromClaims($identity->claims ?? []);
        $applicationId = $identity->application_id
            ?? $preferredApplicationId
            ?? $this->applicationClaimFromClaims($identity->claims ?? []);
        $resolvedApplicationId = $this->ensureApplication($tenantId, $applicationId);
        $internalUserId = $this->ensureInternalUser(
            $tenantId,
            $identity->internal_user_id,
            ['source' => 'identity-backfill'],
        );

        if (
            $identity->tenant_id !== $tenantId
            || $identity->application_id !== $resolvedApplicationId
            || $identity->internal_user_id !== $internalUserId
        ) {
            $identity->tenant_id = $tenantId;
            $identity->application_id = $resolvedApplicationId;
            $identity->internal_user_id = $internalUserId;
            $identity->save();
        }

        return $identity;
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

    private function ensureApplication(string $tenantId, ?string $requestedApplicationId): string
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

    /**
     * @param array<string, mixed> $metadata
     */
    private function ensureInternalUser(string $tenantId, ?string $internalUserId, array $metadata): string
    {
        if (is_string($internalUserId) && trim($internalUserId) !== '') {
            $existing = $this->internalUsers->findById($internalUserId);
            if ($existing !== null && $existing->tenant_id === $tenantId) {
                return $existing->id;
            }

            if ($existing !== null) {
                $internalUserId = null;
            }
        }

        $resolvedId = is_string($internalUserId) && trim($internalUserId) !== ''
            ? $internalUserId
            : $this->internalUsers->nextId();
        $this->internalUsers->create([
            'id' => $resolvedId,
            'tenant_id' => $tenantId,
            'metadata_json' => $metadata,
        ]);

        return $resolvedId;
    }

    private function tenantIdFromClaims(array $claims): string
    {
        foreach ($this->tenantClaimKeys() as $key) {
            $value = $claims[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return $this->defaultTenantId();
    }

    private function applicationClaimFromClaims(array $claims): ?string
    {
        foreach ($this->applicationClaimKeys() as $key) {
            $value = $claims[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
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

    private function defaultTenantId(): string
    {
        return (string) $this->config->get('authz.identity_bridge.default_tenant_id', 'default');
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
