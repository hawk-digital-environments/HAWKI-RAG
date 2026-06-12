<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Validation;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineDataValidator
{
    public function __construct(
        private ScrapeElementValidator $scrapeElements,
        private PipelineValidationValueNormalizer $values,
    ) {
    }

    /**
     * @return array{errors: array<int, string>, warnings: array<int, string>}
     */
    public function validateScrapeElement(array $data): array
    {
        return $this->scrapeElements->validate($data);
    }

    public function firstScalar(mixed $value): ?string
    {
        return $this->values->firstScalar($value);
    }
}
