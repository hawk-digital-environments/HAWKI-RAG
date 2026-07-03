<?php

declare(strict_types=1);

namespace App\Services\Authorization\Repositories;

use App\Models\AuthorizationIdentity;
use App\Models\User;
use App\Services\Authorization\Values\ResolvedUserIdentity;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;

#[Singleton]
readonly class AuthorizationIdentityRepository
{
    public function findByUser(User $user): ?AuthorizationIdentity
    {
        $identity = AuthorizationIdentity::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        return $identity instanceof AuthorizationIdentity ? $identity : null;
    }

    public function findByIssuerAndSubject(string $issuer, string $subject): ?AuthorizationIdentity
    {
        $identity = AuthorizationIdentity::query()
            ->where('issuer', $issuer)
            ->where('subject', $subject)
            ->first();

        return $identity instanceof AuthorizationIdentity ? $identity : null;
    }

    /**
     * @param list<string|null> $identifiers
     */
    public function findByTenantAndIdentifiers(string $tenantId, array $identifiers): ?AuthorizationIdentity
    {
        $candidates = array_values(array_unique(array_filter(
            array_map(fn (?string $value): ?string => $this->stringValue($value), $identifiers),
        )));

        if ($candidates === []) {
            return null;
        }

        $identity = AuthorizationIdentity::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($candidates): void {
                $query->whereIn('external_user_id', $candidates)
                    ->orWhereIn('email', $candidates)
                    ->orWhereIn('username', $candidates);
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        return $identity instanceof AuthorizationIdentity ? $identity : null;
    }

    /**
     * @param array<string, string|null> $context
     */
    public function upsertFromResolved(ResolvedUserIdentity $identity, array $context = []): AuthorizationIdentity
    {
        $record = $this->findByIssuerAndSubject($identity->issuer, $identity->subject);

        if (! $record instanceof AuthorizationIdentity) {
            $record = new AuthorizationIdentity([
                'issuer' => $identity->issuer,
                'subject' => $identity->subject,
            ]);
            $record->user_id = $this->findOrCreateUser($identity)->id;
        }

        $record->fill([
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

    public function upsertLocalUser(User $user, string $tenantId, string $applicationId, string $internalUserId): AuthorizationIdentity
    {
        $record = $this->findByIssuerAndSubject('local://rawki', 'user:'.$user->id);

        if (! $record instanceof AuthorizationIdentity) {
            $record = new AuthorizationIdentity([
                'issuer' => 'local://rawki',
                'subject' => 'user:'.$user->id,
            ]);
            $record->user_id = $user->id;
        }

        $record->fill([
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
    ): AuthorizationIdentity {
        $issuer = 'rawki://identity/'.$tenantId;
        $subject = 'identifier:'.hash('sha256', $identifier);
        $record = $this->findByIssuerAndSubject($issuer, $subject);

        if (! $record instanceof AuthorizationIdentity) {
            $record = new AuthorizationIdentity([
                'issuer' => $issuer,
                'subject' => $subject,
            ]);
            $record->user_id = $this->createPlaceholderUser($identifier, $tenantId)->id;
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

    private function findOrCreateUser(ResolvedUserIdentity $identity): User
    {
        if ($identity->email !== null) {
            $existing = User::query()->where('email', $identity->email)->first();
            if ($existing instanceof User) {
                return $existing;
            }
        }

        $user = new User([
            'username' => $identity->username ?? $identity->externalUserId,
            'email' => $identity->email ?? $identity->externalUserId.'@oidc.local',
            'ip' => 'oidc:'.Str::limit(hash('sha256', $identity->issuer.'|'.$identity->subject), 48, ''),
        ]);
        $user->save();

        return $user;
    }

    private function createPlaceholderUser(string $identifier, string $tenantId): User
    {
        $hash = hash('sha256', $tenantId.'|'.$identifier);
        $user = new User([
            'username' => Str::limit($identifier, 255, ''),
            'email' => filter_var($identifier, FILTER_VALIDATE_EMAIL)
                ? $identifier
                : 'identity-'.$hash.'@rawki.local',
            'ip' => 'authz:'.Str::limit($hash, 48, ''),
        ]);
        $user->save();

        return $user;
    }

    private function stringValue(?string $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
