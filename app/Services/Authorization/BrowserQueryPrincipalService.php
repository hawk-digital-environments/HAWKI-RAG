<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\User;
use App\Services\User\Repositories\UserRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;

#[Singleton]
readonly class BrowserQueryPrincipalService
{
    public function __construct(
        private AuthFactory $auth,
        private UserRepository $users,
        private GateContract $gate,
    ) {}

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

        if ($request->headers->has('Authorization')) {
            return null;
        }

        return $this->attach($request, $this->activeUser($this->users->findSoleActive()));
    }

    private function activeUser(?Authenticatable $user): ?User
    {
        if (! $user instanceof User) {
            return null;
        }

        return $this->gate->forUser($user)->allows('access-query-principal') ? $user : null;
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
