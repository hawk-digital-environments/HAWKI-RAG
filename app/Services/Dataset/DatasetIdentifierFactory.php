<?php

declare(strict_types=1);

namespace App\Services\Dataset;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;

#[Singleton]
readonly class DatasetIdentifierFactory
{
    public function datasetId(mixed $value): string
    {
        return $this->stringValue($value) ?? 'default';
    }

    public function safeName(string $value): string
    {
        $safe = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($value))) ?: 'default';
        $safe = trim($safe, '_');

        return $safe !== '' ? $safe : 'default';
    }

    public function displayName(string $datasetId, mixed $value): string
    {
        return $this->stringValue($value) ?? Str::headline(str_replace(['_', '-'], ' ', $datasetId));
    }

    public function qdrantCollection(string $safe): string
    {
        return 'hawki_'.$safe;
    }

    public function neo4jNamespace(string $safe): string
    {
        return 'hawki_'.$safe;
    }

    public function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
