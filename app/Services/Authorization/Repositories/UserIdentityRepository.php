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

    /**
     * @param list<string|null> $identifiers
     */
    public function findByTenantAndIdentifiers(string $tenantId, array $identifiers): ?UserIdentity
    {
        $candidates = $this->normalizedIdentifiers($identifiers);

        if ($candidates === []) {
            return null;
        }

        $identity = UserIdentity::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($candidates): void {
                $query->whereIn('external_user_id', $candidates)
                    ->orWhereIn('email', $candidates)
                    ->orWhereIn('username', $candidates);
            })
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
    public function findAllByIdentifiers(array $identifiers, ?array $tenantIds = null): Collection
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
            ->where(function ($query) use ($candidates): void {
                $query->whereIn('external_user_id', $candidates)
                    ->orWhereIn('email', $candidates)
                    ->orWhereIn('username', $candidates);
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
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
            'provider' => 'local',
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
    ): UserIdentity {
        $issuer = 'rawki://identity/'.$tenantId;
        $subject = 'identifier:'.hash('sha256', $identifier);
        $record = $this->findByIssuerAndSubject($issuer, $subject);

        if (! $record instanceof UserIdentity) {
            $record = new UserIdentity([
                'issuer' => $issuer,
                'subject' => $subject,
            ]);
        }

        $record->fill([
            'provider' => 'tenant-identity',
            'external_user_id' => $identifier,
            'email' => filter_var($identifier, FILTER_VALIDATE_EMAIL) ? $identifier : null,
            'username' => $identifier,
            'claims' => [
                'source' => 'group-member',
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
