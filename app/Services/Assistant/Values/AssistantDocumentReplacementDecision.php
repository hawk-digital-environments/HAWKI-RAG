<?php

declare(strict_types=1);

namespace App\Services\Assistant\Values;

readonly class AssistantDocumentReplacementDecision
{
    private function __construct(
        public bool $replace,
        public ?string $reason,
    ) {
    }

    public static function replace(): self
    {
        return new self(true, null);
    }

    public static function skip(string $reason): self
    {
        return new self(false, $reason);
    }
}
