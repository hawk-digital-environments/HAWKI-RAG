<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Authorization\Repositories\UserIdentityRepository;
use App\Services\Authorization\Values\ResolvedUserIdentity;
use App\Services\SpecV2\Values\GroupMemberAssignment;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class IdentityProvisioningService
{
    public function __construct(
        private UserIdentityRepository $identities,
        private IdentityContextResolver $context,
        private InternalUserProvisioner $internalUsers,
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

        $tenantId = $this->context->defaultTenantId();
        $applicationId = $this->context->ensureApplication($tenantId, null);
        $internalUserId = $this->internalUsers->ensure($tenantId, null, ['source' => 'local-api']);

        return $this->identities->upsertLocalUser($user, $tenantId, $applicationId, $internalUserId);
    }

    public function upsertResolvedIdentity(ResolvedUserIdentity $identity, ?User $user = null): UserIdentity
    {
        $tenantId = $this->context->tenantIdFromClaims($identity->claims);
        $existing = $this->identities->findByIssuerAndSubject($identity->issuer, $identity->subject);
        $candidate = $this->identities->findByTenantAndIdentifiers($tenantId, [
            $identity->externalUserId,
            $identity->email,
            $identity->username,
        ]);

        $applicationId = $this->context->ensureApplication(
            $tenantId,
            $this->context->applicationIdFromClaims($identity->claims),
        );
        $internalUserId = $this->internalUsers->ensure(
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
        return $this->userAssignments($tenantId, $applicationId, $identifiers);
    }

    /**
     * @param list<string> $identifiers
     * @return list<GroupMemberAssignment>
     */
    public function userAssignments(string $tenantId, string $applicationId, array $identifiers): array
    {
        $resolvedApplicationId = $this->context->ensureApplication($tenantId, $applicationId);
        $assignments = [];

        foreach ($identifiers as $identifier) {
            $identity = $this->identities->findByTenantAndIdentifiers($tenantId, [$identifier]);

            if ($identity instanceof UserIdentity) {
                $identity = $this->hydrateIdentityContext($identity, $tenantId, $resolvedApplicationId);
            } else {
                $internalUserId = $this->internalUsers->ensure($tenantId, null, [
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
            ?? $this->context->tenantIdFromClaims($identity->claims ?? []);
        $applicationId = $identity->application_id
            ?? $preferredApplicationId
            ?? $this->context->applicationIdFromClaims($identity->claims ?? []);
        $resolvedApplicationId = $this->context->ensureApplication($tenantId, $applicationId);
        $internalUserId = $this->internalUsers->ensure(
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
}
