<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\Dataset;
use App\Models\DatasetGrant;
use App\Models\DatasetGrantToken;
use App\Models\User;
use App\Services\Authorization\Exceptions\GrantTokenInvalidException;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\Facades\DB;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

#[Singleton]
final readonly class DatasetGrantTokenService
{
    private const int TTL_HOURS = 24;

    private const string TOKEN_PREFIX = 'dgt_';

    public function __construct(
        private DatasetIngestionAuthorizationService $authorization,
        private ClockInterface $clock = new Clock,
    ) {}

    /**
     * Issues a one-time grant token for a freshly created dataset. Only the
     * sha256 hash is persisted; the plaintext is returned exactly once and
     * never stored or logged.
     */
    public function issue(Dataset $dataset): string
    {
        $plaintext = self::TOKEN_PREFIX.bin2hex(random_bytes(32));

        DatasetGrantToken::query()->create([
            'dataset_id' => $dataset->dataset_id,
            'token_hash' => hash('sha256', $plaintext),
            'expires_at' => $this->clock->now()->modify(sprintf('+%d hours', self::TTL_HOURS)),
        ]);

        return $plaintext;
    }

    /**
     * Redeems a one-time grant token into an ingest grant for the user.
     * Consumption and grant creation are atomic: the token row is locked,
     * marked consumed, and the grant created in one transaction, so a token
     * can only ever mint a single grant — the winner of a redemption race
     * takes it, everyone else sees it consumed.
     */
    public function redeem(Dataset $dataset, User $user, string $plaintext): DatasetGrant
    {
        return DB::transaction(function () use ($dataset, $user, $plaintext): DatasetGrant {
            $token = DatasetGrantToken::query()
                ->where('token_hash', hash('sha256', trim($plaintext)))
                ->lockForUpdate()
                ->first();

            $now = $this->clock->now();

            if (
                ! $token instanceof DatasetGrantToken
                || $token->dataset_id !== $dataset->dataset_id
                || $token->consumed_at !== null
                || $now->getTimestamp() > $token->expires_at->getTimestamp()
            ) {
                throw GrantTokenInvalidException::forRedemption();
            }

            $token->forceFill(['consumed_at' => $now])->save();

            return $this->authorization->grantAccess($user, $dataset);
        });
    }
}
