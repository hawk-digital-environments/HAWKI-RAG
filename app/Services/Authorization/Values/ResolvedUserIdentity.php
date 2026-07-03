<?php

declare(strict_types=1);

namespace App\Services\Authorization\Values;

readonly class ResolvedUserIdentity
{
    /**
     * @param array<string, mixed> $claims
     */
    public function __construct(
        public string $issuer,
        public string $subject,
        public string $provider,
        public string $externalUserId,
        public ?string $email,
        public ?string $username,
        public array $claims = [],
    ) {}
}
