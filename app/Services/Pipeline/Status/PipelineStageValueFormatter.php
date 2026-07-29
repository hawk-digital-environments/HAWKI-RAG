<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Status;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineStageValueFormatter
{
    public function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        return (string) $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
