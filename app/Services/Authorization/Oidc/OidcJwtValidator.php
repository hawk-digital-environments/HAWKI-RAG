<?php

declare(strict_types=1);

namespace App\Services\Authorization\Oidc;

use App\Services\Authorization\Values\ResolvedUserIdentity;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\Factory as HttpFactory;

#[Singleton]
readonly class OidcJwtValidator
{
    public function __construct(
        private ConfigRepository $config,
        private HttpFactory $http,
    ) {}

    public function validate(string $jwt): ?ResolvedUserIdentity
    {
        $parts = $this->parse($jwt);
        if ($parts === null || ($parts->header['alg'] ?? null) !== 'RS256') {
            return null;
        }

        $issuer = $this->string($parts->claims['iss'] ?? null);
        $subject = $this->string($parts->claims['sub'] ?? null);
        if ($issuer === null || $subject === null || ! $this->issuerAllowed($issuer)) {
            return null;
        }

        if (! $this->audienceAllowed($parts->claims['aud'] ?? null) || ! $this->timeClaimsAllowed($parts->claims)) {
            return null;
        }

        if (! $this->signatureValid($parts)) {
            return null;
        }

        $provider = $this->string($this->config->get('authz.oidc.provider')) ?? 'keycloak';

        return new ResolvedUserIdentity(
            issuer: $issuer,
            subject: $subject,
            provider: $provider,
            externalUserId: $subject,
            email: $this->string($parts->claims['email'] ?? null),
            username: $this->string($parts->claims['preferred_username'] ?? $parts->claims['name'] ?? null),
            claims: $parts->claims,
        );
    }

    private function parse(string $jwt): ?JwtParts
    {
        $segments = explode('.', trim($jwt));
        if (count($segments) !== 3) {
            return null;
        }

        $header = $this->jsonSegment($segments[0]);
        $claims = $this->jsonSegment($segments[1]);
        $signature = $this->base64UrlDecode($segments[2]);
        if (! is_array($header) || ! is_array($claims) || $signature === null) {
            return null;
        }

        return new JwtParts($header, $claims, $segments[0].'.'.$segments[1], $signature);
    }

    private function signatureValid(JwtParts $parts): bool
    {
        $kid = $this->string($parts->header['kid'] ?? null);
        foreach ($this->jwks() as $key) {
            if ($kid !== null && ($key['kid'] ?? null) !== $kid) {
                continue;
            }

            $pem = $this->rsaPublicKeyPem($key);
            if ($pem === null) {
                continue;
            }

            $verified = openssl_verify($parts->signedPayload, $parts->signature, $pem, OPENSSL_ALGO_SHA256);
            if ($verified === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jwks(): array
    {
        $url = $this->string($this->config->get('authz.oidc.jwks_url'));
        if ($url === null) {
            return [];
        }

        try {
            $response = $this->http->timeout(5)->get($url);
        } catch (\Throwable) {
            return [];
        }

        $keys = $response->json('keys');

        return is_array($keys) ? array_values(array_filter($keys, 'is_array')) : [];
    }

    /**
     * @param array<string, mixed> $key
     */
    private function rsaPublicKeyPem(array $key): ?string
    {
        $n = $this->base64UrlDecode($this->string($key['n'] ?? null) ?? '');
        $e = $this->base64UrlDecode($this->string($key['e'] ?? null) ?? '');
        if ($n === null || $e === null) {
            return null;
        }

        $modulus = $this->asn1Integer($n);
        $exponent = $this->asn1Integer($e);
        $sequence = $this->asn1Sequence($modulus.$exponent);
        $bitString = "\x03".$this->asn1Length(strlen($sequence) + 1)."\x00".$sequence;
        $algorithm = hex2bin('300d06092a864886f70d0101010500');
        $publicKey = $this->asn1Sequence($algorithm.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($publicKey), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    private function asn1Integer(string $value): string
    {
        if ($value !== '' && (ord($value[0]) & 0x80) !== 0) {
            $value = "\x00".$value;
        }

        return "\x02".$this->asn1Length(strlen($value)).$value;
    }

    private function asn1Sequence(string $value): string
    {
        return "\x30".$this->asn1Length(strlen($value)).$value;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private function issuerAllowed(string $issuer): bool
    {
        $configured = $this->string($this->config->get('authz.oidc.issuer'));

        return $configured === null || rtrim($issuer, '/') === rtrim($configured, '/');
    }

    private function audienceAllowed(mixed $audience): bool
    {
        $configured = $this->string($this->config->get('authz.oidc.audience'));
        if ($configured === null) {
            return true;
        }

        $audiences = is_array($audience) ? $audience : [$audience];

        return in_array($configured, array_map('strval', $audiences), true);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function timeClaimsAllowed(array $claims): bool
    {
        $now = time();
        $leeway = (int) $this->config->get('authz.oidc.leeway_seconds', 60);
        $expiresAt = is_numeric($claims['exp'] ?? null) ? (int) $claims['exp'] : null;
        $notBefore = is_numeric($claims['nbf'] ?? null) ? (int) $claims['nbf'] : null;

        if ($expiresAt !== null && $expiresAt + $leeway < $now) {
            return false;
        }

        return ! ($notBefore !== null && $notBefore - $leeway > $now);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonSegment(string $segment): ?array
    {
        $decoded = $this->base64UrlDecode($segment);
        if ($decoded === null) {
            return null;
        }

        $json = json_decode($decoded, true);

        return is_array($json) ? $json : null;
    }

    private function base64UrlDecode(string $value): ?string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($value, true);

        return is_string($decoded) ? $decoded : null;
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
