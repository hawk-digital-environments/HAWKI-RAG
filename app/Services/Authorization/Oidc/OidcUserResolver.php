<?php

declare(strict_types=1);

namespace App\Services\Authorization\Oidc;

use App\Models\User;
use App\Services\Authorization\IdentityProvisioningService;
use App\Services\Authorization\Values\ResolvedUserIdentity;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

#[Singleton]
readonly class OidcUserResolver
{
    public function __construct(
        private OidcJwtValidator $validator,
        private IdentityProvisioningService $identityProvisioning,
    ) {}

    public function userFromRequest(Request $request): ?User
    {
        $token = $request->bearerToken();
        if ($token === null || substr_count($token, '.') !== 2) {
            return null;
        }

        $identity = $this->validator->validate($token);
        if ($identity === null) {
            return null;
        }

        $user = $this->resolveHumanUser($identity);
        $record = $this->identityProvisioning->upsertResolvedIdentity($identity, $user);

        return $record->user ?? $user;
    }

    private function resolveHumanUser(ResolvedUserIdentity $identity): User
    {
        if ($identity->email !== null) {
            $existing = User::query()->where('email', $identity->email)->first();
            if ($existing instanceof User) {
                return $existing;
            }
        }

        if ($identity->username !== null) {
            $existing = User::query()->where('username', $identity->username)->first();
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
