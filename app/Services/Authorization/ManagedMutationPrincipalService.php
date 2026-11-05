<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\User;
use App\Services\Authorization\Repositories\ManagedMutationTokenRepository;
use App\Services\User\Repositories\UserRepository;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;
use Psr\Clock\ClockInterface;

#[Singleton]
readonly class ManagedMutationPrincipalService
{
    public function __construct(
        private AuthFactory $auth,
        private UserRepository $users,
        private ConfigRepository $config,
        private ClockInterface $clock,
        private ManagedMutationTokenRepository $tokens,
    ) {}

    public function resolve(Request $request): ?User
    {
        // An explicit credential must validate independently of a browser session.
        // Dataset authority remains Python's; no new token ability is invented.
        if ($request->headers->has('Authorization')) {
            $header = $request->header('Authorization');
            if (! is_string($header) || preg_match('/\ABearer ([^\s]+)\z/i', $header, $match) !== 1) {
                return null;
            }
            $token = $this->tokens->find($match[1]);
            $expiration = $this->config->get('sanctum.expiration');
            $now = $this->clock->now()->getTimestamp();
            if (! $token || ($token->expires_at && $token->expires_at->getTimestamp() <= $now)
                || ($expiration && $token->created_at->getTimestamp() <= $now - (int) $expiration * 60)) {
                return null;
            }
            $user = $token->tokenable;
            if ($user instanceof User) {
                $user->withAccessToken($token);
            }
        } else {
            $user = $request->user() ?? $this->auth->guard('sanctum')->user() ?? $this->users->findSoleActive();
        }
        if (! $user instanceof User || $user->isRemoved) {
            return null;
        }
        $request->setUserResolver(static fn (): User => $user);

        return $user;
    }
}
