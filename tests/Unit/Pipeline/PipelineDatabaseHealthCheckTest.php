<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Services\Pipeline\Health\PipelineDatabaseHealthCheck;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Tests\TestCase;

class PipelineDatabaseHealthCheckTest extends TestCase
{
    public function test_it_reports_the_active_database_connection_config(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('select')->once()->with('select 1 as ok')->andReturn([['ok' => 1]]);
        $connection->shouldReceive('getName')->once()->andReturn('pipeline_unit');

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->once()->andReturn($connection);

        $config = Mockery::mock(ConfigRepository::class);
        $config->shouldReceive('get')
            ->once()
            ->with('database.connections.pipeline_unit', [])
            ->andReturn([
                'host' => 'database.test',
                'port' => '3307',
                'database' => 'rawki_unit',
            ]);

        $result = new PipelineDatabaseHealthCheck($config, $database)->check();

        $this->assertSame('ok', $result['status']);
        $this->assertSame('Database', $result['name']);
        $this->assertStringContainsString('pipeline_unit', $result['detail']);
        $this->assertStringContainsString('database.test:3307/rawki_unit', $result['detail']);
    }
}
