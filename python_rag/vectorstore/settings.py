"""Qdrant client settings."""
from __future__ import annotations

import os
from dataclasses import dataclass


@dataclass(frozen=True)
class QdrantSettings:
    scheme: str
    host: str
    port: int
    collection: str
    api_key: str | None
    timeout: float
    max_attempts: int

    @property
    def base_url(self) -> str:
        return f"{self.scheme}://{self.host}:{self.port}"


def qdrant_settings_from_env() -> QdrantSettings:
    return QdrantSettings(
        scheme=os.environ.get("QDRANT_SCHEME", "http"),
        host=os.environ.get("QDRANT_HOST", "qdrant"),
        port=_int_env("QDRANT_PORT", 6333),
        collection=os.environ.get("QDRANT_COLLECTION", "").strip(),
        api_key=os.environ.get("QDRANT_API_KEY"),
        timeout=_float_env("QDRANT_TIMEOUT", 30.0),
        max_attempts=_int_env("QDRANT_RETRY_ATTEMPTS", 3),
    )


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
