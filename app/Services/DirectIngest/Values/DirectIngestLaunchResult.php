<?php
declare(strict_types=1);

namespace App\Services\DirectIngest\Values;

readonly class DirectIngestLaunchResult
{
    private function __construct(
        public array $payload,
        public int $status,
    ) {
    }

    public static function fromPayload(array $payload, int $status = 200): self
    {
        return new self($payload, $status);
    }
}
