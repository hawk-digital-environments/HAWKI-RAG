<?php

declare(strict_types=1);

namespace App\Services\Assistant\Values;

readonly class AssistantDocumentSyncState
{
    /**
     * @param array<string, mixed> $attributes
     * @param array<int, array<string, mixed>> $outputs
     */
    public function __construct(
        public array $attributes,
        public array $outputs,
    ) {
    }
}
