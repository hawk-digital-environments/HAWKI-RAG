<?php

declare(strict_types=1);

namespace App\Services\Authorization\Values;

readonly class LmsUserIdentity
{
    public function __construct(
        public string $provider,
        public string $externalUserId,
        public ?string $email = null,
        public ?string $username = null,
    ) {}
}
