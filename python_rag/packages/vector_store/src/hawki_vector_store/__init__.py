"""Public vector-store contracts and the Qdrant adapter."""

from hawki_vector_store.client import QdrantHTTP, ScopedCollectionNotReadyError
from hawki_vector_store.contracts import VectorPoint, VectorSearchHit
from hawki_vector_store.settings import (
    QdrantHTTPSettings,
    QdrantSettings,
    qdrant_http_settings_from_env,
    qdrant_settings_from_env,
)

__all__ = [
    "QdrantHTTP",
    "QdrantHTTPSettings",
    "QdrantSettings",
    "ScopedCollectionNotReadyError",
    "VectorPoint",
    "VectorSearchHit",
    "qdrant_http_settings_from_env",
    "qdrant_settings_from_env",
]
