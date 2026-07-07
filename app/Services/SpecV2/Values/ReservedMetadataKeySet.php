<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Values;

final class ReservedMetadataKeySet
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            'and',
            'or',
            'not',
            'heap',
            'document_id',
            'owner_app',
            'visibility',
            'protected',
            '__rawki',
        ];
    }

    public static function contains(string $key): bool
    {
        return in_array(strtolower(trim($key)), self::all(), true);
    }
}
