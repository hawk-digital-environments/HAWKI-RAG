<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Smoke;

readonly class PipelineSmokeConversionResult
{
    public function __construct(
        public string $convertJobId,
        public string $convertedPath,
    ) {
    }
}
