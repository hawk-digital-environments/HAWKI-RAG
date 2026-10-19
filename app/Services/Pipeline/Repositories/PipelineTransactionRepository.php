<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;

#[Singleton]
readonly class PipelineTransactionRepository
{
    public function __construct(private DatabaseManager $database) {}

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(callable $callback): mixed
    {
        return $this->database->transaction($callback);
    }

    /** Serialize a logical operation for the lifetime of the current PostgreSQL transaction. */
    public function lock(string $key): void
    {
        if ($this->database->connection()->getDriverName() === 'pgsql') {
            $this->database->select('select pg_advisory_xact_lock(hashtext(?))', [$key]);
        }
    }

    public function afterCommit(callable $callback): void
    {
        if ($this->database->connection()->transactionLevel() > 0) {
            $this->database->connection()->afterCommit($callback);
        } else {
            $callback();
        }
    }
}
