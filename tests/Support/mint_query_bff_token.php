<?php

declare(strict_types=1);

// Test-only subprocess adapter. Reads an ephemeral test key from stdin, never env.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Models\User;
use App\Services\Authorization\QueryTokenSigner;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Config\Repository;
use Psr\Clock\ClockInterface;

$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$path = tempnam(sys_get_temp_dir(), 'query-bff-interop-');
chmod($path, 0600);
try {
    file_put_contents($path, $input['private_pem']);
    $variant = $input['variant'];
    $time = $input['time'] - ($variant === 'expired' ? 120 : 0);
    $config = new Repository(['config' => ['query_bff' => [
        'issuer' => $variant === 'issuer' ? 'https://wrong.example.test' : 'https://bff.example.test',
        'audience' => $variant === 'audience' ? 'wrong-audience' : 'hawki-backend',
        'client_id' => $variant === 'client' ? 'unknown-client' : 'hawki-bff',
        'tenant' => 'default', 'private_key_path' => $path,
        'key_id' => $variant === 'kid' ? 'unknown-key' : 'test-bff-key', 'token_ttl' => 60,
    ]]]);
    $clock = new class($time) implements ClockInterface
    {
        public function __construct(private readonly int $timestamp) {}

        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('@'.$this->timestamp);
        }
    };
    $user = new User;
    $user->id = 42;
    $token = (new QueryTokenSigner($config, $clock))->sign($user);
    if ($variant === 'scope') {
        JWT::$timestamp = $time;
        $key = openssl_pkey_get_private($input['private_pem']);
        $claims = (array) JWT::decode($token, new Key(openssl_pkey_get_details($key)['key'], 'RS256'));
        unset($claims['scope']);
        $token = JWT::encode($claims, $key, 'RS256', 'test-bff-key');
    }
    echo $token;
} finally {
    unlink($path);
}
