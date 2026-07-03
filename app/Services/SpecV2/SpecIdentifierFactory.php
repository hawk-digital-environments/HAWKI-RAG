<?php
declare(strict_types=1);

namespace App\Services\SpecV2;

use App\Services\SpecV2\Exceptions\InvalidGroupIdentifierException;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Str;

#[Singleton]
readonly class SpecIdentifierFactory
{
    public function identifier(mixed $value, string $prefix): string
    {
        $string = $this->stringValue($value);
        if ($string !== null) {
            return $this->safeIdentifier($string);
        }

        return $prefix.'-'.strtolower((string) Str::ulid());
    }

    public function safeIdentifier(string $value): string
    {
        $safe = preg_replace('/[^a-z0-9_-]+/', '-', strtolower(trim($value))) ?: '';
        $safe = trim($safe, '-_');

        return $safe !== '' ? $safe : 'default';
    }

    public function displayName(string $fallbackId, mixed $value): string
    {
        return $this->stringValue($value) ?? Str::headline(str_replace(['_', '-'], ' ', $fallbackId));
    }

    public function namespacedGroupId(string $applicationId, string $groupId): string
    {
        $localId = strtolower(trim($groupId));

        if ($localId === '' || str_contains($localId, ':') || ! preg_match('/^[a-z0-9_-]+$/', $localId)) {
            throw InvalidGroupIdentifierException::becauseReservedCharactersWereUsed($groupId);
        }

        return $applicationId.':'.$localId;
    }

    /**
     * @param list<mixed> $values
     * @return list<string>
     */
    public function stringList(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            $string = $this->stringValue($value);
            if ($string !== null) {
                $normalized[] = $string;
            }
        }

        return array_values(array_unique($normalized));
    }

    public function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
