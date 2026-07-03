<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Repositories;

use App\Models\SpecV2\InternalUser;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;

#[Singleton]
readonly class InternalUserRepository
{
    public function findById(string $internalUserId): ?InternalUser
    {
        return InternalUser::query()
            ->where('id', $internalUserId)
            ->first();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function create(array $attributes): InternalUser
    {
        return InternalUser::query()->create($attributes);
    }

    public function nextId(): string
    {
        return (string) Str::uuid();
    }
}
