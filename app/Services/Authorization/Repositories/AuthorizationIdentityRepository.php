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
            ->latest('id')
            ->first();

        return $identity instanceof AuthorizationIdentity ? $identity : null;
    }

    public function upsertFromResolved(ResolvedUserIdentity $identity): AuthorizationIdentity
    {
        $record = AuthorizationIdentity::query()
            ->where('issuer', $identity->issuer)
            ->where('subject', $identity->subject)
            ->first();

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
}
