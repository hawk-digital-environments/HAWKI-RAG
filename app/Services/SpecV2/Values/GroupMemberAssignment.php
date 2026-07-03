<?php
declare(strict_types=1);

namespace App\Services\SpecV2\Values;

readonly class GroupMemberAssignment
{
    public function __construct(
        public string $userIdentifier,
        public string $internalUserId,
    ) {}
}
