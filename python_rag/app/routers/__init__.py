"""API router builders for the Python RAG FastAPI app."""

from .config import build_config_router
from .health import build_health_router
from .ingest import build_ingest_router
from .query import build_query_router
from .graph import build_graph_router

__all__ = [
    "build_config_router",
    "build_health_router",
    "build_graph_router",
    "build_ingest_router",
    "build_query_router",
]
