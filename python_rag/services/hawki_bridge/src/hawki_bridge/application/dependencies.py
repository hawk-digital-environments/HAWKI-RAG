"""Bridge application dependency contracts."""

from dataclasses import dataclass

from hawki_bridge.domain.ports import GraphSearchPort, VectorSearchFactory


@dataclass(frozen=True, slots=True)
class BridgeDependencies:
    """Storage collaborators supplied by the bridge composition root."""

    vector_search_factory: VectorSearchFactory
    graph_search: GraphSearchPort


__all__ = ["BridgeDependencies"]
