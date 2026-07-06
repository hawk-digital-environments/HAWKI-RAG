<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\SpecV2\Values\ReservedMetadataKeySet;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class DisallowReservedMetadataKeys implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($this->reservedPaths($value) as $path) {
            $fail("The {$attribute} field contains the reserved key {$path}.");
        }
    }

    /**
     * @param array<mixed> $metadata
     * @return list<string>
     */
    private function reservedPaths(array $metadata, string $prefix = ''): array
    {
        $paths = [];

        foreach ($metadata as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $path = $prefix === '' ? $key : $prefix.'.'.$key;
            if (ReservedMetadataKeySet::contains($key)) {
                $paths[] = $path;
            }

            if (is_array($value)) {
                $paths = [...$paths, ...$this->reservedPaths($value, $path)];
            }
        }

        return $paths;
    }
}
