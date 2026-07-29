<?php

declare(strict_types=1);

namespace App\Services\Profile\Values;

enum ApiTokenAbility: string
{
    case Query = 'query';
    case Admin = 'admin';
}
