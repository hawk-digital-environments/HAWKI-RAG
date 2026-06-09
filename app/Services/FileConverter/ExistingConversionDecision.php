<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

readonly class ExistingConversionDecision
{
    public function __construct(
        public bool $cancelled,
        public bool $forceReprocess,
        public int $existingOutputs,
    ) {
    }
}
