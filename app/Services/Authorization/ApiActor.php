<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\AuthorizationIdentity;
use App\Models\SpecV2\Application;
use App\Models\User;

readonly class ApiActor
{
    private const TYPE_APPLICATION = 'application';
    private const TYPE_USER = 'user';

    private function __construct(
        private string $type,
        private Application $application,
        private ?User $user = null,
        private ?AuthorizationIdentity $identity = null,
    ) {}

    public static function forApplication(Application $application): self
    {
        return new self(self::TYPE_APPLICATION, $application);
    }

    public static function forUser(User $user, Application $application, ?AuthorizationIdentity $identity): self
    {
        return new self(self::TYPE_USER, $application, $user, $identity);
    }

    public function isApplication(): bool
    {
        return $this->type === self::TYPE_APPLICATION;
    }

    public function isUser(): bool
    {
        return $this->type === self::TYPE_USER;
    }

    public function application(): Application
    {
        return $this->application;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function identity(): ?AuthorizationIdentity
    {
        return $this->identity;
    }

    public function tenantId(): string
    {
        return (string) $this->application->tenant_id;
    }

    public function applicationId(): string
    {
        return (string) $this->application->id;
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return array_values(array_filter(
            is_array($this->application->permissions) ? $this->application->permissions : [],
            static fn (mixed $permission): bool => is_string($permission) && trim($permission) !== '',
        ));
    }

    public function hasApplicationPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
