<?php

declare(strict_types=1);

namespace App\Services\Graph;

class Neo4jAdmin
{
    public function __construct(private readonly Neo4jClient $neo4j) {}

    public function clearDataset(string $datasetId, string $namespace): array
    {
        return $this->neo4j->clearDataset($datasetId, $namespace);
    }
}
