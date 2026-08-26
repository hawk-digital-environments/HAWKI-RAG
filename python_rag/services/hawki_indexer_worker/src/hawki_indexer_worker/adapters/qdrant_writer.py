"""Indexer composition surface for Qdrant writes."""

from hawki_vector_store.client import QdrantHTTP
from hawki_indexer_worker.domain.ports import VectorWriterPort


def create_qdrant_writer() -> VectorWriterPort:
    return QdrantHTTP()


__all__ = ["create_qdrant_writer"]
