"""Settings for the Qdrant vector-store adapter."""

from __future__ import annotations

import os
from dataclasses import dataclass, field


@dataclass(frozen=True)
class QdrantSettings:
    scheme: str
    host: str
    port: int
    collection: str
    api_key: str | None
    timeout: float
    max_attempts: int
    retry_attempts_by_operation: dict[str, int] = field(default_factory=dict)

    @property
    def base_url(self) -> str:
        return f"{self.scheme}://{self.host}:{self.port}"


@dataclass(frozen=True)
class QdrantHTTPSettings:
    log_latency: bool
    search_all: bool
    search_all_per_collection: int
    fallback_all: bool
    fallback_per_collection: int
    upsert_timeout: float
    search_timeout: float
    count_timeout: float
    delete_timeout: float
    text_timeout: float
    text_fallback_terms: int
    text_scroll_hard_cap: int
    text_scroll_batch: int


def qdrant_settings_from_env() -> QdrantSettings:
    default_attempts = _int_env("QDRANT_RETRY_ATTEMPTS", 3)
    upsert_attempts = _int_env("QDRANT_RETRY_ATTEMPTS_UPSERT", default_attempts)
    delete_attempts = _int_env("QDRANT_RETRY_ATTEMPTS_DELETE", default_attempts)
    count_attempts = _int_env("QDRANT_RETRY_ATTEMPTS_COUNT", default_attempts)
    search_attempts = _int_env("QDRANT_RETRY_ATTEMPTS_SEARCH", default_attempts)
    scroll_attempts = _int_env("QDRANT_RETRY_ATTEMPTS_SCROLL", default_attempts)
    collection_read_attempts = _int_env("QDRANT_RETRY_ATTEMPTS_READ", default_attempts)
    collection_create_attempts = _int_env(
        "QDRANT_RETRY_ATTEMPTS_COLLECTION_CREATE", default_attempts
    )
    return QdrantSettings(
        scheme=os.environ.get("QDRANT_SCHEME", "http"),
        host=os.environ.get("QDRANT_HOST", "qdrant"),
        port=_int_env("QDRANT_PORT", 6333),
        collection=os.environ.get("QDRANT_COLLECTION", "").strip(),
        api_key=os.environ.get("QDRANT_API_KEY"),
        timeout=_float_env("QDRANT_TIMEOUT", 30.0),
        max_attempts=default_attempts,
        retry_attempts_by_operation={
            "qdrant.upsert_points": upsert_attempts,
            "qdrant.delete_by_filter": delete_attempts,
            "qdrant.points.count": count_attempts,
            "qdrant.points.search": search_attempts,
            "qdrant.points.scroll": scroll_attempts,
            "qdrant.collections.get": collection_read_attempts,
            "qdrant.collections.list": collection_read_attempts,
            "qdrant.collections.create": collection_create_attempts,
        },
    )


def qdrant_http_settings_from_env(
    base_timeout: float | None = None,
) -> QdrantHTTPSettings:
    fallback_timeout = 30.0 if base_timeout is None else float(base_timeout)
    return QdrantHTTPSettings(
        log_latency=_bool_env("QDRANT_LOG_LATENCY"),
        search_all=_bool_env("QDRANT_SEARCH_ALL"),
        search_all_per_collection=_int_env("QDRANT_SEARCH_ALL_PER_COLLECTION", 0),
        fallback_all=_bool_env("QDRANT_FALLBACK_ALL", True),
        fallback_per_collection=_int_env("QDRANT_FALLBACK_PER_COLLECTION", 0),
        upsert_timeout=_float_env("QDRANT_UPSERT_TIMEOUT", fallback_timeout),
        search_timeout=_float_env("QDRANT_SEARCH_TIMEOUT", fallback_timeout),
        count_timeout=_float_env("QDRANT_COUNT_TIMEOUT", fallback_timeout),
        delete_timeout=_float_env("QDRANT_DELETE_TIMEOUT", fallback_timeout),
        text_timeout=_float_env("QDRANT_SEARCH_TIMEOUT", fallback_timeout),
        text_fallback_terms=_int_env("QDRANT_TEXT_FALLBACK_TERMS", 3),
        text_scroll_hard_cap=_int_env("QDRANT_TEXT_SCROLL_HARD_CAP", 50000),
        text_scroll_batch=_int_env("QDRANT_TEXT_SCROLL_BATCH", 256),
    )


def _bool_env(name: str, default: bool = False) -> bool:
    """Parse common truthy env values with a fallback default."""
    value = os.environ.get(name)
    if value is None:
        return default
    return value.lower() in ("1", "true", "yes", "on")


def _int_env(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, default))
    except Exception:
        return default


def _float_env(name: str, default: float) -> float:
    try:
        return float(os.environ.get(name, default))
    except Exception:
        return default
