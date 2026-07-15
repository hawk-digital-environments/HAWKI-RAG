<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Models\User;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

readonly class OperatorAccessService
{
    public function __construct(
        private AuthFactory $auth,
        private ConfigRepository $config,
    ) {}

    public function allows(Request $request): bool
    {
        if ($this->localBypassIsAllowed()) {
            return true;
        }

        $user = $request->user() ?? $this->auth->guard('sanctum')->user();
        if (! $user instanceof User || (bool) $user->isRemoved) {
            return false;
        }

        $accessToken = $user->currentAccessToken();

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

        return app()->environment($environments);
    }
}
