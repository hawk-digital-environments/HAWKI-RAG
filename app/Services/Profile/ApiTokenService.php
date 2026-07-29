<?php

declare(strict_types=1);

namespace App\Services\Profile;

use App\Models\User;
use App\Services\Profile\Exceptions\ProfileTokenException;
use App\Services\Profile\Values\ApiTokenAbility;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Collection;
use Laravel\Sanctum\NewAccessToken;
use Psr\Log\LoggerInterface;

class ApiTokenService
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  non-empty-list<ApiTokenAbility>  $abilities
     */
    public function createApiToken(string $name, array $abilities): NewAccessToken
    {
        return $this->currentUser()->createToken(
            $name,
            array_map(
                static fn (ApiTokenAbility $ability): string => $ability->value,
                $abilities,
            ),
        );
    }

    /**
     * @return Collection<int, array{id: int, name: string, abilities: list<string>}>
     */
    public function fetchTokenList(): Collection
    {
        $tokens = $this->currentUser()->tokens()->get();

        return $tokens->map(function ($token) {
            return [
                'id' => $token->id,
                'name' => $token->name,
                'abilities' => is_array($token->abilities) ? $token->abilities : [],
            ];
        });
    }

    public function revokeToken(int $tokenId): void
    {
        try {
            $token = $this->currentUser()->tokens()->where('id', $tokenId);
            $token->delete();
        } catch (\Throwable $e) {
            $this->logger->error('Profile API token revoke failed.', [
                'token_id' => $tokenId,
                'exception' => $e,
            ]);

            throw ProfileTokenException::revokeFailed($tokenId, $e);
        }
    }

    private function currentUser(): User
    {
        $user = $this->auth->guard()->user();
        if (! $user instanceof User) {
            throw ProfileTokenException::missingAuthenticatedUser();
        }

        return $user;
    }
}
