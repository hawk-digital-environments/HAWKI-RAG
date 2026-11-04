<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\User;
use App\Services\Authorization\Exceptions\QueryTokenSigningException;
use Firebase\JWT\JWT;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;

#[Singleton]
readonly class QueryTokenSigner
{
    private const QUERY_SCOPE = 'rag:query';

    /**
     * The direct-text boundary's coarse scope. It is a property of the action,
     * never caller input, and the dataset grant is still checked by Python.
     */
    private const INGEST_SCOPE = 'rag:ingest';

    public function __construct(
        private ConfigRepository $config,
        private ClockInterface $clock,
    ) {}

    public function sign(User $user): string
    {
        return $this->encode($user, self::QUERY_SCOPE);
    }

    public function signIngest(User $user): string
    {
        return $this->encode($user, self::INGEST_SCOPE);
    }

    private function encode(User $user, string $scope): string
    {
        try {
            $issuer = $this->text('issuer');
            $audience = $this->text('audience');
            $client = $this->text('client_id');
            if ($scope === self::INGEST_SCOPE && $client !== 'hawki-bff') {
                throw QueryTokenSigningException::unavailable();
            }
            $tenant = $this->text('tenant');
            $kid = $this->text('key_id');
            $subject = (string) $user->getAuthIdentifier();
            $configuredTtl = $this->config->get('config.query_bff.token_ttl');
            $ttl = is_int($configuredTtl) || is_string($configuredTtl)
                ? filter_var($configuredTtl, FILTER_VALIDATE_INT) : false;
            if ($subject === '' || strlen($subject) > 255 || $user->isRemoved
                || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $tenant)
                || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/', $kid)
                || $ttl === false || $ttl < 1 || $ttl > 60) {
                throw QueryTokenSigningException::unavailable();
            }

            $path = $this->text('private_key_path');
            if (! is_file($path) || ! is_readable($path)) {
                throw QueryTokenSigningException::unavailable();
            }
            $pem = @file_get_contents($path);
            $key = is_string($pem) ? @openssl_pkey_get_private($pem) : false;
            $details = $key === false ? false : openssl_pkey_get_details($key);
            if ($details === false || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
                throw QueryTokenSigningException::unavailable();
            }

            $issuedAt = $this->clock->now()->getTimestamp();

            return JWT::encode([
                'iss' => $issuer,
                'aud' => $audience,
                'sub' => $subject,
                'tenant_id' => $tenant,
                'azp' => $client,
                'scope' => $scope,
                'iat' => $issuedAt,
                'exp' => $issuedAt + $ttl,
                'jti' => Uuid::uuid4()->toString(),
            ], $key, 'RS256', $kid);
        } catch (\Throwable) {
            // Do not retain a previous exception, OpenSSL warning, key path or claims.
        }

        throw QueryTokenSigningException::unavailable();
    }

    private function text(string $name): string
    {
        $value = $this->config->get('config.query_bff.'.$name);
        if (! is_string($value) || $value === '' || $value !== trim($value)) {
            throw QueryTokenSigningException::unavailable();
        }

        return $value;
    }
}
