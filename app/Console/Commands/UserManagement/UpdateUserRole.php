<?php

declare(strict_types=1);

namespace App\Console\Commands\UserManagement;

use App\Services\User\Repositories\UserRepository;
use App\Services\User\Values\UserRole;
use Illuminate\Console\Command;

class UpdateUserRole extends Command
{
    protected $signature = 'user:role
        {userId : The persisted user ID}
        {role : One of: user, admin}';

    protected $description = 'Assign a persisted application role to a user';

    public function handle(UserRepository $users): int
    {
        $userId = (string) $this->argument('userId');
        $user = $users->findById($userId);
        if ($user === null) {
            $this->error("User {$userId} was not found.");

            return self::FAILURE;
        }

        $roleValue = strtolower(trim((string) $this->argument('role')));
        $role = UserRole::tryFrom($roleValue);
        if ($role === null) {
            $this->error("Role {$roleValue} is invalid. Expected user or admin.");

            return self::FAILURE;
        }

        $users->setRole($user, $role);
        $this->info("User {$userId} role updated to {$role->value}.");

        return self::SUCCESS;
    }
}
