<?php

declare(strict_types=1);

namespace App\Services\Authorization\Repositories;

use Illuminate\Container\Attributes\Singleton;
use Laravel\Sanctum\PersonalAccessToken;

#[Singleton]
readonly class ManagedMutationTokenRepository
{
    public function find(#[\SensitiveParameter] string $token): ?PersonalAccessToken
    {
        return PersonalAccessToken::findToken($token);
    }
}
