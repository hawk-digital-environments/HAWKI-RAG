<?php

declare(strict_types=1);

namespace App\Services\User\Repositories;

use App\Models\User;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class UserRepository
{
    public function create(string $username, string $email, string $ip): User
    {
        $user = new User([
            'username' => $username,
            'email' => $email,
            'ip' => $ip,
        ]);
        $user->save();

        return $user;
    }

    public function findByUsername(string $username): ?User
    {
        $user = User::query()->where('username', $username)->first();

        return $user instanceof User ? $user : null;
    }

    public function findByEmail(string $email): ?User
    {
        $user = User::query()->where('email', $email)->first();

        return $user instanceof User ? $user : null;
    }

    public function findById(string $id): ?User
    {
        $user = User::query()->find($id);

        return $user instanceof User ? $user : null;
    }

    public function markRemoved(User $user): void
    {
        $user->remove();
        $user->save();
    }
}
