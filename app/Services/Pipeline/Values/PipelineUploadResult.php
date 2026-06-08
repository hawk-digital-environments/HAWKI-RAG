<?php
declare(strict_types=1);

namespace App\Services\Pipeline\Values;

readonly class PipelineUploadResult
{
    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        public array $payload,
        public int $status,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload, int $status): self
    {
        return new self($payload, $status);
    }
}
