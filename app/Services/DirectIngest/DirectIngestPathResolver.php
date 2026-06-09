<?php

declare(strict_types=1);

namespace App\Services\DirectIngest;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Foundation\Application;

#[Singleton]
readonly class DirectIngestPathResolver
{
    public function __construct(
        private Application $app,
    ) {
    }

    public function basePath(string $path = ''): string
    {
        return $this->app->basePath($path);
    }

    public function storagePath(string $path = ''): string
    {
        return $this->app->storagePath($path);
    }
}
