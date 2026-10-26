"""hawki-rag-retrieval: Shared RAG query/retrieval package."""

__version__ = "0.1.0"

from hawki_rag_retrieval.dependencies import QueryDependencies
from hawki_rag_retrieval.errors import (
    AnswerGenerationError,
    DatasetVectorStoreNotReadyError,
    EmbeddingGenerationError,
    InvalidQueryError,
    UnsupportedModelProviderError,
)
from hawki_rag_retrieval.ports import (
    GraphReader,
    GraphSearchPort,
    ModelProvider,
    ModelProviderResolver,
    RerankHitsPort,
    ScopedFilters,
    VectorSearchFactory,
    VectorSearchPort,
)
from hawki_rag_retrieval.query import execute_authorized_query

__all__ = [
    "AnswerGenerationError",
    "DatasetVectorStoreNotReadyError",
    "EmbeddingGenerationError",
    "GraphReader",
    "GraphSearchPort",
    "InvalidQueryError",
    "ModelProvider",
    "ModelProviderResolver",
    "QueryDependencies",
    "RerankHitsPort",
    "ScopedFilters",
    "UnsupportedModelProviderError",
    "VectorSearchFactory",
    "VectorSearchPort",
    "execute_authorized_query",
]
