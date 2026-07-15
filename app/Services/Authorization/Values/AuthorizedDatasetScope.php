<?php

declare(strict_types=1);

namespace App\Services\Authorization\Values;

final readonly class AuthorizedDatasetScope
{
    private function __construct(
        public string $datasetId,
        public string $qdrantCollection,
        public string $neo4jNamespace,
        public string $embeddingModel,
        public bool $graphEnabled,
    ) {}

    public static function fromStorageTargets(
        string $datasetId,
        string $qdrantCollection,
        string $neo4jNamespace,
        string $embeddingModel,
    ): self {
        return new self(
            datasetId: $datasetId,
            qdrantCollection: $qdrantCollection,
            neo4jNamespace: $neo4jNamespace,
            embeddingModel: $embeddingModel,
            graphEnabled: true,
        );
    }

    /**
     * @return array{dataset_id:string,qdrant_collection:string,neo4j_namespace:string,embedding_model:string,graph_enabled:true}
     */
    public function toArray(): array
    {
        return [
            'dataset_id' => $this->datasetId,
            'qdrant_collection' => $this->qdrantCollection,
            'neo4j_namespace' => $this->neo4jNamespace,
            'embedding_model' => $this->embeddingModel,
            'graph_enabled' => $this->graphEnabled,
        ];
    }
}
