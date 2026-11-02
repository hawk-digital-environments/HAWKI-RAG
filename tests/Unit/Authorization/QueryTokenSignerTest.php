<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Models\User;
use App\Services\Authorization\Exceptions\QueryTokenSigningException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Tests\Support\QueryBffFixture;

class QueryTokenSignerTest extends TestCase
{
    private QueryBffFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new QueryBffFixture;
        JWT::$timestamp = QueryBffFixture::TIME;
    }

    protected function tearDown(): void
    {
        JWT::$timestamp = null;
        $this->fixture->close();
    }

    public function test_fixed_query_contract_is_signed_for_the_resolved_user(): void
    {
        $user = new User;
        $user->id = 42;
        $token = $this->fixture->signer()->sign($user);
        $header = new \stdClass;
        $claims = (array) JWT::decode($token, new Key($this->fixture->publicPem, 'RS256'), $header);
        self::assertEquals((object) ['typ' => 'JWT', 'alg' => 'RS256', 'kid' => 'test-bff-key'], $header);
        $jti = $claims['jti'];
        self::assertTrue(Uuid::isValid($jti));
        unset($claims['jti']);
        self::assertSame([
            'iss' => 'https://bff.example.test', 'aud' => 'hawki-backend', 'sub' => '42',
            'tenant_id' => 'default', 'azp' => 'hawki-bff', 'scope' => 'rag:query',
            'iat' => QueryBffFixture::TIME, 'exp' => QueryBffFixture::TIME + 60,
        ], $claims);
        $second = (array) JWT::decode($this->fixture->signer()->sign($user), new Key($this->fixture->publicPem, 'RS256'));
        self::assertNotSame($jti, $second['jti']);
    }

    public function test_shorter_configured_lifetime_is_used_exactly(): void
    {
        $this->fixture->config->set('config.query_bff.token_ttl', 30);
        $user = new User;
        $user->id = 7;
        $claims = JWT::decode($this->fixture->signer()->sign($user), new Key($this->fixture->publicPem, 'RS256'));
        self::assertSame(30, $claims->exp - $claims->iat);
    }

    #[DataProvider('invalidConfiguration')]
    public function test_invalid_configuration_is_a_safe_unchained_failure(string $name, mixed $value): void
    {
        $this->fixture->config->set('config.query_bff.'.$name, $value);
        $this->assertSafeFailure();
    }

    public static function invalidConfiguration(): array
    {
        return [
            ['issuer', ''], ['audience', ''], ['client_id', ''], ['tenant', '*'], ['tenant', '../tenant'],
            ['key_id', ''], ['key_id', '../key'], ['private_key_path', '/missing/sensitive-private-key.pem'],
            ['token_ttl', 0], ['token_ttl', -1], ['token_ttl', 61], ['token_ttl', 300],
            ['token_ttl', 'garbage'], ['token_ttl', 1.5], ['token_ttl', true],
        ];
    }

    public function test_invalid_pem_is_not_exposed(): void
    {
        file_put_contents($this->fixture->privatePath, 'PRIVATE-KEY-CONTENT-MUST-NOT-LEAK');
        $this->assertSafeFailure();
    }

    public function test_unreadable_key_is_refused(): void
    {
        chmod($this->fixture->privatePath, 0000);
        $this->assertSafeFailure();
    }

    #[DataProvider('invalidKeys')]
    public function test_wrong_or_weak_key_is_refused(array $options): void
    {
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $pem);
        file_put_contents($this->fixture->privatePath, $pem);
        $this->assertSafeFailure();
    }

    public static function invalidKeys(): array
    {
        return [
            [['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']],
            [['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]],
        ];
    }

    public function test_missing_subject_and_removed_user_cannot_be_signed(): void
    {
        $this->expectException(QueryTokenSigningException::class);
        $this->fixture->signer()->sign(new User);
    }

    public function test_removed_user_cannot_be_signed(): void
    {
        $user = new User;
        $user->id = 42;
        $user->isRemoved = true;
        $this->expectException(QueryTokenSigningException::class);
        $this->fixture->signer()->sign($user);
    }

    private function assertSafeFailure(): void
    {
        $user = new User;
        $user->id = 42;
        try {
            $this->fixture->signer()->sign($user);
            self::fail('Signing must fail.');
        } catch (QueryTokenSigningException $error) {
            self::assertSame('Query authentication is not available.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString($this->fixture->privatePath, (string) $error);
            self::assertStringNotContainsString('PRIVATE-KEY-CONTENT', (string) $error);
        }
    }
}
