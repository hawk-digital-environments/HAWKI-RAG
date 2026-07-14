<?php

declare(strict_types=1);

namespace App\Services\Authorization\Values;

final readonly class AuthenticatedPrincipal
{
    public const TYPE_USER = 'user';

    private function __construct(
        public string $type,
        public string $id,
    ) {}

    public static function tryFromUserIdentifier(int|string|null $identifier): ?self
    {
        $id = trim((string) $identifier);

        return $id === '' ? null : new self(self::TYPE_USER, $id);
    }
}
