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
readonly class StaticLmsPermissionConnector implements LmsPermissionConnector
{
    public function __construct(private ConfigRepository $config) {}

    public function providerId(): string
    {
        return (string) $this->config->get('authz.connectors.static.provider', 'local');
    }

    public function resolveUser(string $issuer, string $subject, array $claims): LmsUserIdentity
    {
        $externalId = $this->string($claims['preferred_username'] ?? $claims['email'] ?? null) ?? $subject;

        return new LmsUserIdentity(
            provider: $this->providerId(),
            externalUserId: $externalId,
            email: $this->string($claims['email'] ?? null),
            username: $this->string($claims['preferred_username'] ?? $claims['name'] ?? null),
        );
    }

    public function membershipsForUser(LmsUserIdentity $user): iterable
    {
        foreach ($this->rows('authz.connectors.static.memberships') as $row) {
            [$externalUserId, $courseId, $role] = array_pad(explode(':', $row, 3), 3, null);
            if ($externalUserId === $user->externalUserId && $courseId) {
                yield new LmsMembership($this->providerId(), $user->externalUserId, $courseId, $role ?: 'member');
            }
        }
    }

    public function documentsForCourse(string $courseId): iterable
    {
        foreach ($this->rows('authz.connectors.static.documents') as $row) {
            [$configuredCourseId, $documentId] = array_pad(explode(':', $row, 2), 2, null);
            if ($configuredCourseId === $courseId && $documentId) {
                yield new LmsDocumentRelation($this->providerId(), $courseId, $documentId);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function rows(string $key): array
    {
        $value = (string) $this->config->get($key, '');

        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $value) ?: [])));
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
