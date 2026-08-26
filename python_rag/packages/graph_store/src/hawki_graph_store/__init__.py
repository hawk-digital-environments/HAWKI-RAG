"""Public graph-store contracts and the Neo4j adapter."""

from hawki_graph_store.contracts import GraphFact, GraphScope, GraphTriplet
from hawki_graph_store.graph import Neo4jGraph
from hawki_graph_store.ports import GraphReader, GraphWriter
from hawki_graph_store.settings import Neo4jSettings, load_neo4j_settings
from hawki_graph_store.transport import (
    Neo4jQueryExecutor,
    Neo4jQueryExecutorProtocol,
)

__all__ = [
    "GraphFact",
    "GraphReader",
    "GraphScope",
    "GraphTriplet",
    "GraphWriter",
    "Neo4jGraph",
    "Neo4jQueryExecutor",
    "Neo4jQueryExecutorProtocol",
    "Neo4jSettings",
    "load_neo4j_settings",
]
