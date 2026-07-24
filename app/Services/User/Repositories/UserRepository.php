<?php

declare(strict_types=1);

namespace App\Services\User\Repositories;

use App\Models\User;
use App\Services\User\Values\UserRole;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class UserRepository
{
    public function create(
        string $username,
        string $email,
        string $ip,
        UserRole $role = UserRole::User,
    ): User {
        $user = new User([
            'username' => $username,
            'email' => $email,
            'ip' => $ip,
        ]);
        $user->role = $role;
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

    public function findSoleActive(): ?User
    {
        $users = User::query()
            ->where('isRemoved', false)
            ->limit(2)
            ->get();

        if ($users->count() !== 1) {
            return null;
        }

        $user = $users->first();

        return $user instanceof User ? $user : null;
    }

    public function markRemoved(User $user): void
    {
        $user->remove();
        $user->save();
    }

    public function setRole(User $user, UserRole $role): void
    {
        $user->role = $role;
        $user->save();
    }
}
