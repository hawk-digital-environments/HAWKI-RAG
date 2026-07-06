<?php
declare(strict_types=1);

namespace App\Services\Authorization;

use App\Services\SpecV2\Repositories\InternalUserRepository;
use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class InternalUserProvisioner
{
    public function __construct(
        private InternalUserRepository $internalUsers,
    ) {}

    /**
     * @param array<string, mixed> $metadata
     */
    public function ensure(string $tenantId, ?string $internalUserId, array $metadata): string
    {
        if (is_string($internalUserId) && trim($internalUserId) !== '') {
            $existing = $this->internalUsers->findById($internalUserId);
            if ($existing !== null && $existing->tenant_id === $tenantId) {
                return $existing->id;
            }

            if ($existing !== null) {
                $internalUserId = null;
            }
        }

        $resolvedId = is_string($internalUserId) && trim($internalUserId) !== ''
            ? $internalUserId
            : $this->internalUsers->nextId();
        $this->internalUsers->create([
            'id' => $resolvedId,
            'tenant_id' => $tenantId,
            'metadata_json' => $metadata,
        ]);

        return $resolvedId;
    }
}
