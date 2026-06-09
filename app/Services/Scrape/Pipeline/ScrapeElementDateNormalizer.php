<?php

declare(strict_types=1);

namespace App\Services\Scrape\Pipeline;

use DateTimeImmutable;
use Illuminate\Container\Attributes\Singleton;
use Throwable;

#[Singleton]
readonly class ScrapeElementDateNormalizer
{
    public function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }
}
