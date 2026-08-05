"""Indexer composition surface for Qdrant writes."""

from hawki_rag_stores.qdrant.client import QdrantHTTP


def create_qdrant_writer() -> QdrantHTTP:
    return QdrantHTTP()


__all__ = ["create_qdrant_writer"]
