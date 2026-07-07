<?php

declare(strict_types=1);

namespace App\Services\Pipeline\Health;

use Illuminate\Container\Attributes\Singleton;

#[Singleton]
readonly class PipelineHealthService
{
    public function __construct(
        private PipelineDatabaseHealthCheck $database,
        private PipelineTemporalHealthCheck $temporal,
        private PipelineWorkerHealthCheck $workers,
        private QdrantHealthCheck $qdrant,
        private Neo4jHealthCheck $neo4j,
        private PipelineSharedStorageHealthCheck $sharedStorage,
    ) {
    }

    /**
     * @return list<array{name:string,status:string,detail:string,fix:string}>
     */
    public function check(int $timeout): array
    {
        $timeout = max(1, $timeout);

        return [
            $this->database->check(),
            $this->temporal->check(),
            $this->workers->workflow(),
            $this->workers->converter($timeout),
            $this->workers->ingestion($timeout),
            $this->qdrant->check($timeout),
            $this->neo4j->check($timeout),
            $this->sharedStorage->check(),
        ];
    }
}
