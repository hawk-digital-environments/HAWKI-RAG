<?php

declare(strict_types=1);

namespace App\Services\Storage;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class StoragePathBuilder
{
    public function folder(string $id, ?string $urlHash = null): string
    {
        if ($urlHash === null) {
            return $id;
        }

        $dir = implode('/', str_split(substr($urlHash, 0, 4), 1));

        return $id.'/'.$dir.'/'.$urlHash;
    }
}
