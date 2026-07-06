<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Models\SpecV2\Application;
use App\Services\SpecV2\Repositories\ApplicationRepository;
use Illuminate\Container\Attributes\Singleton;
#[Singleton]
readonly class ApplicationTokenService
{
    private const TOKEN_PREFIX = 'hawki_app';

    public function __construct(
        private ApplicationRepository $applications,
    ) {}

    public function issue(Application $application): string
    {
        $secret = bin2hex(random_bytes(32));
        $application->token_hash = hash('sha256', $secret);
        $application->save();

        return self::TOKEN_PREFIX.'.'.$this->encode($application->id).'.'.$secret;
    }

    public function authenticate(?string $token): ?Application
    {
        [$applicationId, $secret] = $this->parse($token);
        if ($applicationId === null || $secret === null) {
            return null;
        }

        $application = $this->applications->findById($applicationId);
        if (! $application instanceof Application) {
            return null;
        }

        $expected = is_string($application->token_hash) ? trim($application->token_hash) : '';
        if ($expected === '') {
            return null;
        }

        return hash_equals($expected, hash('sha256', $secret)) ? $application : null;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function parse(?string $token): array
    {
        if (! is_string($token) || trim($token) === '') {
            return [null, null];
        }

        $parts = explode('.', trim($token), 3);
        if (count($parts) !== 3 || $parts[0] !== self::TOKEN_PREFIX) {
            return [null, null];
        }

        $applicationId = $this->decode($parts[1]);
        $secret = trim($parts[2]);

        return [
            $applicationId !== '' ? $applicationId : null,
            $secret !== '' ? $secret : null,
        ];
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $normalized = strtr($value, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);

        return is_string($decoded) ? $decoded : '';
    }
}
