<?php

declare(strict_types=1);

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $this->assertSafeTestDatabaseEnvironment();

        parent::setUp();
    }

    public function createApplication(): Application
    {
        $app = parent::createApplication();

        $this->assertSafeTestDatabaseConfiguration($app);

        return $app;
    }

    protected function actingAsApiUser(): User
    {
        $user = User::query()->create([
            'username' => 'api-test-'.uniqid(),
            'email' => 'api-test-'.uniqid().'@example.test',
            'ip' => '127.0.0.'.random_int(1, 254),
        ]);

        Sanctum::actingAs($user);

        return $user;
    }

    private function assertSafeTestDatabaseEnvironment(): void
    {
        $environment = $this->testEnvironmentVariable('APP_ENV');
        $connection = $this->testEnvironmentVariable('DB_CONNECTION');
        $database = $this->testEnvironmentVariable('DB_DATABASE');
        $url = $this->testEnvironmentVariable('DB_URL');

        if ($environment === 'testing' && $connection === 'sqlite' && $database === ':memory:' && $url === '') {
            return;
        }

        self::fail(sprintf(
            'Refusing to run tests with an unsafe database environment. Expected APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory:, and an empty DB_URL; got APP_ENV=%s, DB_CONNECTION=%s, DB_DATABASE=%s, DB_URL=%s.',
            $environment ?: '<unset>',
            $connection ?: '<unset>',
            $database ?: '<unset>',
            $url === '' ? '<unset>' : '<set>',
        ));
    }

    private function assertSafeTestDatabaseConfiguration(Application $app): void
    {
        $environment = (string) $app->environment();
        $connection = (string) $app['config']->get('database.default');
        $database = (string) $app['config']->get('database.connections.sqlite.database');
        $url = $app['config']->get('database.connections.sqlite.url');
        $cacheStore = (string) $app['config']->get('cache.default');
        $cacheConnection = (string) $app['config']->get('cache.stores.database.connection');
        $cacheLockConnection = (string) $app['config']->get('cache.stores.database.lock_connection');
        $queueConnection = (string) $app['config']->get('queue.default');
        $queueDatabaseConnection = (string) $app['config']->get('queue.connections.database.connection');
        $sessionDriver = (string) $app['config']->get('session.driver');
        $sessionConnection = (string) $app['config']->get('session.connection');

        if (
            $environment === 'testing'
            && $connection === 'sqlite'
            && $database === ':memory:'
            && ($url === null || $url === '')
            && $cacheStore === 'array'
            && $cacheConnection === 'sqlite'
            && $cacheLockConnection === 'sqlite'
            && $queueConnection === 'sync'
            && $queueDatabaseConnection === 'sqlite'
            && $sessionDriver === 'array'
            && $sessionConnection === 'sqlite'
        ) {
            return;
        }

        self::fail(sprintf(
            'Refusing to run tests with unsafe resolved storage configuration. Expected testing with in-memory SQLite plus array cache, sync queue, and array session; got environment=%s, database=%s/%s, url=%s, cache=%s/%s/%s, queue=%s/%s, session=%s/%s.',
            $environment ?: '<unset>',
            $connection ?: '<unset>',
            $database ?: '<unset>',
            ($url === null || $url === '') ? '<unset>' : '<set>',
            $cacheStore ?: '<unset>',
            $cacheConnection ?: '<unset>',
            $cacheLockConnection ?: '<unset>',
            $queueConnection ?: '<unset>',
            $queueDatabaseConnection ?: '<unset>',
            $sessionDriver ?: '<unset>',
            $sessionConnection ?: '<unset>',
        ));
    }

    private function testEnvironmentVariable(string $name): string
    {
        $value = getenv($name);

        if ($value === false) {
            $value = $_ENV[$name] ?? '';
        }

        return trim((string) $value);
    }
}
