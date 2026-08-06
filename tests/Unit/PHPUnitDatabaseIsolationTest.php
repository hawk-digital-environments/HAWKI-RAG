<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class PHPUnitDatabaseIsolationTest extends TestCase
{
    public function test_phpunit_forces_the_in_memory_sqlite_database(): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->assertEmpty(config('database.connections.sqlite.url'));
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('sqlite', config('cache.stores.database.connection'));
        $this->assertSame('sqlite', config('cache.stores.database.lock_connection'));
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame('sqlite', config('queue.connections.database.connection'));
        $this->assertSame('array', config('session.driver'));
        $this->assertSame('sqlite', config('session.connection'));
    }
}
