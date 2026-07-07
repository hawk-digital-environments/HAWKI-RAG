<?php

declare(strict_types=1);

namespace App\Services\Authorization\Repositories;

use App\Models\User;
use App\Models\UserIdentity;
use App\Services\Authorization\Values\ResolvedUserIdentity;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Collection;

#[Singleton]
readonly class UserIdentityRepository
{
    public function findByUser(User $user): ?UserIdentity
    {
        $identity = UserIdentity::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        return $identity instanceof UserIdentity ? $identity : null;
    }

    public function findByIssuerAndSubject(string $issuer, string $subject): ?UserIdentity
    {
        $identity = UserIdentity::query()
            ->where('issuer', $issuer)
            ->where('subject', $subject)
            ->first();

        return $identity instanceof UserIdentity ? $identity : null;
    }

    public function findByTenantAndProviderAndExternalUserId(
        string $tenantId,
        string $provider,
        string $externalUserId,
    ): ?UserIdentity
    {
        $resolvedExternalUserId = $this->stringValue($externalUserId);
        if ($resolvedExternalUserId === null) {
            return null;
        }

        $identity = UserIdentity::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', $provider)
            ->where('external_user_id', $resolvedExternalUserId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        return $identity instanceof UserIdentity ? $identity : null;
    }

    /**
     * @param list<string|null> $identifiers
     * @param list<string>|null $tenantIds
     * @return Collection<int, UserIdentity>
     */
    public function findAllSupportedByExternalUserIds(array $identifiers, ?array $tenantIds = null): Collection
    {
        $candidates = $this->normalizedIdentifiers($identifiers);

        if ($candidates === []) {
            return collect();
        }

        return UserIdentity::query()
            ->when(
                $tenantIds !== null && $tenantIds !== [],
                fn ($query) => $query->whereIn('tenant_id', $tenantIds),
            )
            ->whereIn('external_user_id', $candidates)
            ->orderBy('tenant_id')
            ->orderBy('external_user_id')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy(function (UserIdentity $identity): string {
                return (string) $identity->tenant_id.'|'.(string) $identity->external_user_id;
            })
            ->map(function (Collection $identities): ?UserIdentity {
                $providers = $identities->pluck('provider')
                    ->filter(fn (mixed $provider): bool => is_string($provider) && trim($provider) !== '')
                    ->unique()
                    ->values();

                if ($providers->count() !== 1) {
                    return null;
                }

                $identity = $identities->first();

                return $identity instanceof UserIdentity ? $identity : null;
            })
            ->filter(fn (mixed $identity): bool => $identity instanceof UserIdentity)
            ->values();
    }

    /**
     * @param array<string, string|null> $context
     */
    public function upsertFromResolved(
        ResolvedUserIdentity $identity,
        array $context = [],
        ?User $user = null,
    ): UserIdentity {
        $record = $this->findByIssuerAndSubject($identity->issuer, $identity->subject);
        $tenantId = isset($context['tenant_id']) && is_string($context['tenant_id']) ? trim($context['tenant_id']) : null;

        if (! $record instanceof UserIdentity && $tenantId !== null && $tenantId !== '') {
            $record = $this->findByTenantAndProviderAndExternalUserId(
                $tenantId,
                $identity->provider,
                $identity->externalUserId,
            );
        }

        if (! $record instanceof UserIdentity) {
            $record = new UserIdentity([
                'issuer' => $identity->issuer,
                'subject' => $identity->subject,
            ]);
        }

        $record->fill([
            'user_id' => $user?->id ?? $record->user_id,
            'provider' => $identity->provider,
            'external_user_id' => $identity->externalUserId,
            'email' => $identity->email,
            'username' => $identity->username,
            'claims' => $identity->claims,
            'tenant_id' => $context['tenant_id'] ?? $record->tenant_id,
            'application_id' => $context['application_id'] ?? $record->application_id,
            'internal_user_id' => $context['internal_user_id'] ?? $record->internal_user_id,
        ]);
        $record->save();

        return $record;
    }

    public function upsertLocalUser(User $user, string $tenantId, string $applicationId, string $internalUserId): UserIdentity
    {
        $record = $this->findByIssuerAndSubject('local://rawki', 'user:'.$user->id);

        if (! $record instanceof UserIdentity) {
            $record = new UserIdentity([
                'issuer' => 'local://rawki',
                'subject' => 'user:'.$user->id,
            ]);
        }

        $record->fill([
            'user_id' => $user->id,
            'provider' => UserIdentity::PROVIDER_LOCAL,
            'external_user_id' => (string) $user->id,
            'email' => $user->email,
            'username' => $user->username,
            'claims' => [
                'source' => 'local-api',
                'tenant_id' => $tenantId,
                'application_id' => $applicationId,
            ],
            'tenant_id' => $tenantId,
            'application_id' => $applicationId,
            'internal_user_id' => $internalUserId,
        ]);
        $record->save();

        return $record;
    }

    public function upsertExternalIdentifier(
        string $tenantId,
        string $applicationId,
        string $internalUserId,
        string $identifier,
        string $provider = UserIdentity::PROVIDER_TENANT_IDENTITY,
        string $source = 'group-member',
    ): UserIdentity {
        $issuer = 'rawki://identity/'.$tenantId.'/'.$provider;
        $subject = 'identifier:'.hash('sha256', $provider.'|'.$identifier);
        $record = $this->findByTenantAndProviderAndExternalUserId($tenantId, $provider, $identifier);

        if (! $record instanceof UserIdentity) {
            $record = new UserIdentity([
                'issuer' => $issuer,
                'subject' => $subject,
            ]);
        }

        $record->fill([
            'provider' => $provider,
            'external_user_id' => $identifier,
            'email' => null,
            'username' => null,
            'claims' => [
                'source' => $source,
                'tenant_id' => $tenantId,
                'application_id' => $applicationId,
                'provider' => $provider,
            ],
            'tenant_id' => $tenantId,
            'application_id' => $applicationId,
            'internal_user_id' => $internalUserId,
        ]);
        $record->save();

        return $record;
    }

    public function attachUser(UserIdentity $identity, User $user): UserIdentity
    {
        if ((int) $identity->user_id === (int) $user->id) {
            return $identity;
        }

        $identity->user_id = $user->id;
        $identity->save();

        return $identity;
    }

    /**
     * @param list<string|null> $identifiers
     * @return list<string>
     */
    private function normalizedIdentifiers(array $identifiers): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn (?string $value): ?string => $this->stringValue($value), $identifiers),
        )));
    }

    private function stringValue(?string $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
