"""Environment-backed settings used by the query pipeline."""

from __future__ import annotations

import os

MAX_CONTEXT_TOKENS_DEFAULT = 2800
ITERATIVE_RETRIEVAL_ENV = "RAG_ITERATIVE_RETRIEVAL"


def int_env(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, default))
    except Exception:
        return default


def float_env(name: str, default: float) -> float:
    try:
        return float(os.environ.get(name, default))
    except Exception:
        return default


def bool_env(name: str, default: bool = False) -> bool:
    raw = os.environ.get(name)
    if raw is None or str(raw).strip() == "":
        return default
    return str(raw).strip().lower() in ("1", "true", "yes")


def search_top_k(requested_top_k: int) -> int:
    search_mult = max(1, int_env("RAG_SEARCH_TOP_K_MULT", 3))
    search_cap = max(10, int_env("RAG_SEARCH_TOP_K_CAP", 50))
    return min(max(requested_top_k, requested_top_k * search_mult), search_cap)


def fusion_weights() -> tuple[float, float]:
    return (
        float_env("RAG_FUSION_SEM_WEIGHT", 0.6),
        float_env("RAG_FUSION_STR_WEIGHT", 0.4),
    )


def score_thresholds() -> tuple[float, float]:
    return (
        float_env("RAG_MIN_SCORE", 0.1),
        float_env("RAG_MIN_SCORE_FALLBACK", 0.2),
    )


def context_limits() -> tuple[int, int]:
    return (
        int_env("RAG_CONTEXT_TOKENS", MAX_CONTEXT_TOKENS_DEFAULT),
        int_env("RAG_CONTEXT_DOCS", 6),
    )


def iterative_retrieval_enabled() -> bool:
    return bool_env(ITERATIVE_RETRIEVAL_ENV, True)


def generation_enabled() -> bool:
    return bool_env("RAG_GENERATE_ANSWER", False)
