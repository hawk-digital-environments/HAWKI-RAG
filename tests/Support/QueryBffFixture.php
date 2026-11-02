<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Authorization\QueryTokenSigner;
use Illuminate\Config\Repository;
use Psr\Clock\ClockInterface;

/** Ephemeral keys live in the OS temp directory, never deployment configuration. */
final class QueryBffFixture
{
    public const TIME = 1793620800;

    public readonly string $privatePath;

    public readonly string $publicPem;

    public readonly Repository $config;

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->privatePath = tempnam(sys_get_temp_dir(), 'query-bff-test-');
        chmod($this->privatePath, 0600);
        file_put_contents($this->privatePath, $pem);
        $this->publicPem = openssl_pkey_get_details($key)['key'];
        $this->config = new Repository(['config' => [
            'query_backend' => 'python',
            'python_query_url' => 'http://python.test/v1/query',
            'hawki_rag_query_timeout' => 300,
            'query_bff' => [
                'issuer' => 'https://bff.example.test',
                'audience' => 'hawki-backend',
                'client_id' => 'hawki-bff',
                'tenant' => 'default',
                'private_key_path' => $this->privatePath,
                'key_id' => 'test-bff-key',
                'token_ttl' => 60,
            ],
        ]]);
    }

    public function signer(): QueryTokenSigner
    {
        return new QueryTokenSigner($this->config, new class implements ClockInterface
        {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('@'.QueryBffFixture::TIME);
            }
        });
    }

    public function close(): void
    {
        chmod($this->privatePath, 0600);
        unlink($this->privatePath);
    }
}
