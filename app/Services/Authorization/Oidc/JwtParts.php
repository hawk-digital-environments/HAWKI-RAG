<?php

declare(strict_types=1);

namespace App\Services\Authorization\Oidc;

readonly class JwtParts
{
    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    public function __construct(
        public array $header,
        public array $claims,
        public string $signedPayload,
        public string $signature,
    ) {}
}
