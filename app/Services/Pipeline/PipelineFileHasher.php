<?php

declare(strict_types=1);

namespace App\Services\Pipeline;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineFileHasher
{
    public function sha256(string $path): string
    {
        return hash_file('sha256', $path) ?: hash('sha256', $path);
    }
}
