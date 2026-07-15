<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\User;
use App\Services\User\Repositories\UserRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

#[Singleton]
readonly class BrowserQueryPrincipalService
{
    private const QUERY_SESSION_USER_ID = 'hawki_rag.query_user_id';

    public function __construct(
        private AuthFactory $auth,
        private ConfigRepository $config,
        private Application $application,
        private UserRepository $users,
    ) {}

    public function establishSession(Request $request, User $user): void
    {
        $request->session()->regenerate(true);
        $request->session()->put(self::QUERY_SESSION_USER_ID, (string) $user->getAuthIdentifier());
    }

    public function resolve(Request $request): ?User
    {
        $requestUser = $request->user();
        if ($requestUser !== null) {
            if ($request->bearerToken() !== null) {
                return $this->attach($request, $this->activeQueryTokenUser($requestUser));
            }

            return $this->attach($request, $this->activeUser($requestUser));
        }

        $sanctumUser = $this->auth->guard('sanctum')->user();
        if ($sanctumUser !== null) {
            return $this->attach($request, $this->activeQueryTokenUser($sanctumUser));
        }

        if ($request->bearerToken() !== null) {
            return null;
        }

        if ($request->hasSession() && $request->session()->has(self::QUERY_SESSION_USER_ID)) {
            $userId = trim((string) $request->session()->get(self::QUERY_SESSION_USER_ID, ''));
            if ($userId === '' || ! ctype_digit($userId) || (int) $userId < 1) {
                return null;
            }

            return $this->attach($request, $this->activeUser($this->users->findById($userId)));
        }

        if (! $this->developmentBypassIsAllowed()) {
            return null;
        }

        $userId = trim((string) $this->config->get('config.query_auth.development_user_id', ''));
        if ($userId === '' || ! ctype_digit($userId) || (int) $userId < 1) {
            return null;
        }

        return $this->attach($request, $this->activeUser($this->users->findById($userId)));
    }

    private function developmentBypassIsAllowed(): bool
    {
        if (! filter_var($this->config->get('config.query_auth.development_bypass', false), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        if (! $this->application->environment(['local', 'testing'])) {
            return false;
        }

        $environments = $this->config->get(
            'config.query_auth.development_bypass_environments',
            ['local', 'testing'],
        );
        if (! is_array($environments)) {
            $environments = preg_split('/[\s,]+/', (string) $environments) ?: [];
        }

        $environments = array_values(array_filter(array_map(
            static fn (mixed $environment): string => strtolower(trim((string) $environment)),
            $environments,
        )));

        return $this->application->environment($environments);
    }

    private function activeUser(?Authenticatable $user): ?User
    {
        return $user instanceof User && ! (bool) $user->isRemoved ? $user : null;
    }

    private function activeQueryTokenUser(?Authenticatable $user): ?User
    {
        $activeUser = $this->activeUser($user);
        $accessToken = $activeUser?->currentAccessToken();

        return $accessToken !== null && $accessToken->can('query') ? $activeUser : null;
    }

    private function attach(Request $request, ?User $user): ?User
    {
        if ($user !== null) {
            $request->setUserResolver(static fn (): User => $user);
        }

        return $user;
    }
}
