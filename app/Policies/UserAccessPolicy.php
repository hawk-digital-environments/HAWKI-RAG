<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class UserAccessPolicy
{
    public function accessActiveUser(User $user): bool
    {
        return ! (bool) $user->isRemoved;
    }

    public function accessQueryPrincipal(User $user): bool
    {
        // Credential abilities stay at HTTP boundaries; this Gate validates resolved principal state.
        return $this->accessActiveUser($user);
    }
}
