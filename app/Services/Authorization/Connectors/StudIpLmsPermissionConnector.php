<?php

declare(strict_types=1);

namespace App\Services\Authorization\Connectors;

use App\Services\Authorization\Contracts\LmsPermissionConnector;
use App\Services\Authorization\Values\LmsDocumentRelation;
use App\Services\Authorization\Values\LmsMembership;
use App\Services\Authorization\Values\LmsUserIdentity;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

#[Singleton]
readonly class StudIpLmsPermissionConnector implements LmsPermissionConnector
{
    public function __construct(private ConfigRepository $config) {}

    public function providerId(): string
    {
        return 'studip';
    }

    public function resolveUser(string $issuer, string $subject, array $claims): LmsUserIdentity
    {
        return new LmsUserIdentity(
            provider: $this->providerId(),
            externalUserId: $this->string($claims['studip_user_id'] ?? $claims['preferred_username'] ?? null) ?? $subject,
            email: $this->string($claims['email'] ?? null),
            username: $this->string($claims['preferred_username'] ?? null),
        );
    }

    public function membershipsForUser(LmsUserIdentity $user): iterable
    {
        if (! (bool) $this->config->get('authz.connectors.studip.enabled', false)) {
            return [];
        }

        // Scaffold: wire the campus-specific HTTP client here without changing the authorization core.
        return [];
    }

    public function documentsForCourse(string $courseId): iterable
    {
        if (! (bool) $this->config->get('authz.connectors.studip.enabled', false)) {
            return [];
        }

        // Scaffold: emit LmsDocumentRelation values from the plugin-specific document API.
        return [];
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
