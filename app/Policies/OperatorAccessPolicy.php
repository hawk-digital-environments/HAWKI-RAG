<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;

final readonly class OperatorAccessPolicy
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

        // Browser sessions have no current token; bearer credentials must explicitly grant operator access.
        return $accessToken === null || $accessToken->can('operator');
    }

    private function localBypassIsAllowed(): bool
    {
        if (! filter_var($this->config->get('config.operator_auth.bypass', false), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $environments = $this->config->get('config.operator_auth.bypass_environments', ['local', 'testing']);
        if (! is_array($environments)) {
            $environments = preg_split('/[\s,]+/', (string) $environments) ?: [];
        }

        $environments = array_values(array_filter(array_map(
            static fn (mixed $environment): string => strtolower(trim((string) $environment)),
            $environments,
        )));

        return $this->application->environment($environments);
    }
}
