"""External capabilities required by authorized query execution."""

from dataclasses import dataclass

from hawki_bridge.domain.ports import (
    GraphSearchPort,
    ModelProviderResolver,
    RerankHitsPort,
    VectorSearchFactory,
)


@dataclass(frozen=True, slots=True)
class QueryDependencies:
    """I/O collaborators supplied once by the bridge composition root."""

    vector_search_factory: VectorSearchFactory
    graph_search: GraphSearchPort
    resolve_model_provider: ModelProviderResolver
    rerank_hits: RerankHitsPort


__all__ = ["QueryDependencies"]
