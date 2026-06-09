<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Validation;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelinePathSafetyValidator
{
    public function leavesRoot(string $relative): bool
    {
        $normalized = str_replace('\\', '/', $relative);

        return str_starts_with($normalized, '/')
            || str_contains($normalized, '../')
            || $normalized === '..'
            || str_starts_with($normalized, '..');
    }
}
