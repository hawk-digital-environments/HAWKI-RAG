<?php

declare(strict_types=1);

namespace App\Services\Scrape\Validation;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class ScrapeLabelValidator
{
    public function isValid(string $label): bool
    {
        return preg_match('/^[a-zA-Z0-9_-]+$/', $label) === 1;
    }
}
