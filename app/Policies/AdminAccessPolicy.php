<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Services\User\Values\UserRole;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

final readonly class AdminAccessPolicy
{
    public function __construct(
        private Application $application,
        private ConfigRepository $config,
        private UserAccessPolicy $users,
    ) {}

    public function access(?User $user): bool
    {
        if ($this->localBypassIsAllowed()) {
            return true;
        }

        if ($user === null || ! $this->users->accessActiveUser($user)) {
            return false;
        }

        $accessToken = $user->currentAccessToken();
        if ($accessToken === null || $accessToken instanceof TransientToken) {
            return $user->role === UserRole::Admin;
        }

        if (! $accessToken instanceof PersonalAccessToken) {
            return false;
        }

        $abilities = $accessToken->abilities;
        if (! is_array($abilities)) {
            return false;
        }

        if (in_array('admin', $abilities, true)) {
            return true;
        }

        return $this->acceptLegacyOperatorAbility()
            && in_array('operator', $abilities, true);
    }

    private function localBypassIsAllowed(): bool
    {
        if (! $this->application->environment(['local', 'testing'])) {
            return false;
        }

        if (! filter_var($this->config->get('config.admin_auth.bypass', false), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $environments = $this->config->get('config.admin_auth.bypass_environments', ['local', 'testing']);
        if (! is_array($environments)) {
            $environments = preg_split('/[\s,]+/', (string) $environments) ?: [];
        }

        $environments = array_values(array_filter(array_map(
            static fn (mixed $environment): string => strtolower(trim((string) $environment)),
            $environments,
        )));

        return $this->application->environment($environments);
    }

    private function acceptLegacyOperatorAbility(): bool
    {
        return filter_var(
            $this->config->get('config.admin_auth.accept_legacy_operator_ability', false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }
}
