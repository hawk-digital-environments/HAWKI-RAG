<?php

declare(strict_types=1);

namespace App\Services\Authorization\Values;

use App\Models\AuthorizationIdentity;
use App\Models\User;

readonly class RetrievalAuthorizationContext
{
    public function __construct(
        public string $provider,
        public string $userId,
    ) {}

    public static function fromIdentity(AuthorizationIdentity $identity): self
    {
        return new self(
            provider: $identity->provider,
            userId: $identity->external_user_id,
        );
    }

    public static function forLocalUser(User $user): self
    {
        return new self(
            provider: 'local',
            userId: (string) $user->getAuthIdentifier(),
        );
    }

    /**
     * @return array{provider: string, user_id: string}
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'user_id' => $this->userId,
        ];
    }
}
