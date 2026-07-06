<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\File;

#[Singleton]
readonly class CorpusContentReader
{
    public function read(?string $path): ?string
    {
        if (! is_string($path) || trim($path) === '' || ! File::exists($path)) {
            return null;
        }

        return File::get($path);
    }
}
