<?php

declare(strict_types=1);

namespace App\Services\Authorization\Oidc;

use App\Models\User;
use App\Services\Authorization\Repositories\AuthorizationIdentityRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Http\Request;

#[Singleton]
readonly class OidcUserResolver
{
    public function __construct(
        private OidcJwtValidator $validator,
        private AuthorizationIdentityRepository $identities,
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

        $record = $this->identities->upsertFromResolved($identity);

        return $record->user;
    }
}
