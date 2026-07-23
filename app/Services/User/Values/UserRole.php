<?php

declare(strict_types=1);

namespace App\Services\User\Values;

enum UserRole: string
{
    case User = 'user';
    case Admin = 'admin';
}
