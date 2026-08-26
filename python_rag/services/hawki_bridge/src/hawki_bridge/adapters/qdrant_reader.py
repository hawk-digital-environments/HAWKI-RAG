"""Qdrant adapter implementing the bridge-owned vector search port."""

from __future__ import annotations

import logging
from collections.abc import Callable
from dataclasses import dataclass, field
from typing import Any, TypeVar

from requests import RequestException

from hawki_vector_store.client import QdrantHTTP, ScopedCollectionNotReadyError
from hawki_vector_store.strategies import (
    semantic_search_basic,
    semantic_search_high_recall,
    optimized_semantic_search,
    semantic_search_smart,
)
from hawki_bridge.domain.errors import DatasetVectorStoreNotReadyError

logger = logging.getLogger(__name__)
QDRANT_OPERATION_ERRORS = (RequestException,)
ResultT = TypeVar("ResultT")


@dataclass(slots=True)
class QdrantReader:
    """Translate bridge vector operations to the concrete Qdrant client.

    1. Create a request-local Qdrant client when the adapter is composed.
    2. Lock every query to its authorized collection before retrieval.
    3. Execute semantic or lexical searches through the vector-store package.
    4. Translate a missing scoped collection into a bridge-owned error.
    """

    client: QdrantHTTP = field(default_factory=QdrantHTTP)

    def select_scoped_collection(self, collection: str) -> None:
        self.client.select_scoped_collection(collection)

    def search_candidates(
        self,
        *,
        vector: list[float],
        top_k: int,
        filters: dict[str, Any] | None,
        query_terms: list[str],
        keyword_fields: list[str],
        smart_lookup: bool,
        fast_mode: bool,
        is_optimized: bool,
        preferred_tags: list[str] | None,
    ) -> list[dict[str, Any]]:
        return _translate_not_ready(
            lambda: run_search(
                qdrant=self.client,
                vec=vector,
                top_k=top_k,
                filters=filters,
                query_terms=query_terms,
                keyword_fields=keyword_fields,
                smart_lookup=smart_lookup,
                fast_mode=fast_mode,
                is_optimized=is_optimized,
                preferred_tags=preferred_tags,
            )
        )

    def search_high_recall(
        self,
        *,
        vector: list[float],
        top_k: int,
        filters: dict[str, Any] | None,
        preferred_tags: list[str] | None,
    ) -> list[dict[str, Any]]:
        return _translate_not_ready(
            lambda: run_high_recall(
                qdrant=self.client,
                vec=vector,
                top_k=top_k,
                filters=filters,
                preferred_tags=preferred_tags,
            )
        )

    def search_with_text(
        self,
        vector: list[float],
        *,
        top_k: int,
        terms: list[str],
        fields: list[str],
        filters: dict[str, Any] | None = None,
    ) -> list[dict[str, Any]]:
        return _translate_not_ready(
            lambda: self.client.search_with_text(
                vector,
                top_k=top_k,
                terms=terms,
                fields=fields,
                filters=filters,
            )
        )

    def scroll_with_text(
        self,
        *,
        terms: list[str],
        fields: list[str],
        limit: int,
        require_all: bool = True,
        filters: dict[str, Any] | None = None,
    ) -> list[dict[str, Any]]:
        return _translate_not_ready(
            lambda: self.client.scroll_with_text(
                terms=terms,
                fields=fields,
                limit=limit,
                require_all=require_all,
                filters=filters,
            )
        )

    def scroll_with_text_all(
        self,
        *,
        terms: list[str],
        fields: list[str],
        limit: int,
        require_all: bool = True,
        filters: dict[str, Any] | None = None,
    ) -> list[dict[str, Any]]:
        return _translate_not_ready(
            lambda: self.client.scroll_with_text_all(
                terms=terms,
                fields=fields,
                limit=limit,
                require_all=require_all,
                filters=filters,
            )
        )


def _translate_not_ready(operation: Callable[[], ResultT]) -> ResultT:
    try:
        return operation()
    except ScopedCollectionNotReadyError as exc:
        raise DatasetVectorStoreNotReadyError(str(exc)) from exc


def ping_qdrant() -> None:
    """Fail when the configured Qdrant service cannot list collections."""

    QdrantHTTP().list_collections()


def run_search(
    *,
    qdrant: QdrantHTTP,
    vec: list[float],
    top_k: int,
    filters: dict[str, Any] | None,
    query_terms: list[str],
    keyword_fields: list[str],
    smart_lookup: bool,
    fast_mode: bool,
    is_optimized: bool,
    preferred_tags: list[str] | None,
) -> list[dict[str, Any]]:
    if smart_lookup and not fast_mode:
        hits = semantic_search_smart(
            qdrant,
            vec,
            top_k=top_k,
            filters=filters,
            keyword_terms=query_terms,
            keyword_fields=keyword_fields,
        )
        if not hits:
            hits = semantic_search_basic(
                qdrant,
                vec,
                top_k=top_k,
                filters=filters,
            )
        logger.info("search:smart hits=%s", len(hits))
    elif is_optimized and not fast_mode:
        hits = optimized_semantic_search(
            qdrant,
            vec,
            top_k=top_k,
            filters=filters,
            preferred_tags=preferred_tags,
        )
        logger.info("search:optimized hits=%s", len(hits))
    else:
        hits = semantic_search_basic(
            qdrant,
            vec,
            top_k=top_k,
            filters=filters,
        )
        logger.info("search:basic hits=%s", len(hits))
    return hits


def run_high_recall(
    *,
    qdrant: QdrantHTTP,
    vec: list[float],
    top_k: int,
    filters: dict[str, Any] | None,
    preferred_tags: list[str] | None,
) -> list[dict[str, Any]]:
    hits = semantic_search_high_recall(
        qdrant,
        vec,
        top_k=top_k,
        filters=filters,
    )
    if not hits:
        hits = optimized_semantic_search(
            qdrant,
            vec,
            top_k=top_k,
            filters=filters,
            preferred_tags=preferred_tags,
        )
    logger.info("search:high_recall hits=%s", len(hits))
    return hits


__all__ = ["QDRANT_OPERATION_ERRORS", "QdrantReader", "ping_qdrant"]
