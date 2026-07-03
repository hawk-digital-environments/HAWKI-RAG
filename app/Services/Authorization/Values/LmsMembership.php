<?php

declare(strict_types=1);

namespace App\Services\Authorization\Values;

use Illuminate\Support\Carbon;

readonly class LmsMembership
{
    public function __construct(
        public string $provider,
        public string $externalUserId,
        public string $courseId,
        public string $role,
        public ?Carbon $sourceUpdatedAt = null,
    ) {}

    public function relation(): string
    {
        return in_array(strtolower($this->role), ['instructor', 'teacher', 'owner', 'admin'], true)
            ? 'instructor'
            : 'member';
    }
}
