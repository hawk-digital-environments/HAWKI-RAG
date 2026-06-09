<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Health;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;

#[Singleton]
readonly class PipelineDatabaseHealthCheck
{
    public function __construct(
        private ConfigRepository $config,
        private DatabaseManager $database,
    ) {
    }

    /**
     * @return array{name:string,status:string,detail:string,fix:string}
     */
    public function check(): array
    {
        try {
            $connection = $this->database->connection();
            $connection->select('select 1 as ok');
            $connectionName = $connection->getName();
            $connectionConfig = $this->config->get("database.connections.{$connectionName}", []);

            return $this->ok(
                'Database',
                sprintf(
                    'Connected via %s to %s:%s/%s.',
                    $connectionName,
                    $connectionConfig['host'] ?? 'unknown-host',
                    $connectionConfig['port'] ?? 'unknown-port',
                    $connectionConfig['database'] ?? 'unknown-database',
                ),
            );
        } catch (\Throwable $exception) {
            return $this->failureResult(
                'Database',
                $exception->getMessage(),
                'Start MariaDB and verify DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, and DB_PASSWORD.',
            );
        }
    }

    private function ok(string $name, string $detail): array
    {
        return [
            'name' => $name,
            'status' => 'ok',
            'detail' => $detail,
            'fix' => '',
        ];
    }

    private function failureResult(string $name, string $detail, string $fix): array
    {
        return [
            'name' => $name,
            'status' => 'fail',
            'detail' => $detail,
            'fix' => $fix,
        ];
    }
}
