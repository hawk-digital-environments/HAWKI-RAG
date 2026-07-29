<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;

#[Singleton]
readonly class PipelineSchemaInspector
{
    public function __construct(private DatabaseManager $database)
    {
    }

    public function hasTable(string $table): bool
    {
        return $this->database->connection()->getSchemaBuilder()->hasTable($table);
    }

    /**
     * @param list<string> $tables
     */
    public function hasTables(array $tables): bool
    {
        foreach ($tables as $table) {
            if (! $this->hasTable($table)) {
                return false;
            }
        }

        return true;
    }
}
