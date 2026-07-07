<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Values;

readonly class GrantMutationResult
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public array $payload,
        public int $status,
    ) {}
}
