<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Repositories;

use Illuminate\Container\Attributes\Singleton;
use Illuminate\Database\DatabaseManager;

#[Singleton]
readonly class PipelineTransactionRepository
{
    public function __construct(private DatabaseManager $database)
    {
    }

    /**
     * @template TReturn
     * @param callable(): TReturn $callback
     * @return TReturn
     */
    public function run(callable $callback): mixed
    {
        return $this->database->transaction($callback);
    }
}
