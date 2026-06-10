<?php

declare(strict_types=1);

namespace App\Services\FileConverter;

use App\Services\FileConverter\Exceptions\ConversionOutputException;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class DocumentContentHasher
{
    public function sha256(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw ConversionOutputException::unableToHash($path);
        }

        return $hash;
    }
}
