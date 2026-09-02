"""Optional model-assisted rewrite policy for multimodal queries."""

from __future__ import annotations

import json
import logging
import re
from typing import Any, TypedDict

from hawki_bridge.domain.ports import ModelProvider
from hawki_rag_text.terms import extract_terms

logger = logging.getLogger(__name__)

_MULTIMODAL_HINT_PATTERN = re.compile(
    r"\b(figure|fig\.|image|photo|diagram|chart|table|equation|grafik|"
    r"abbildung|tabelle|diagramm|bild|foto|gleichung)\b",
    re.IGNORECASE,
)


class QueryRewrite(TypedDict):
    """Normalized query rewrite produced from optional model output."""

    enabled: bool
    raw: dict[str, Any]
    high_level_keys: list[str]
    low_level_keys: list[str]
    modality_hints: list[str]
    entity_terms: list[str]
    rewritten_query: object


def is_multimodal_query(text: str) -> bool:
    """Return whether the query mentions visual or structured content."""

    return bool(text and _MULTIMODAL_HINT_PATTERN.search(text))


def request_query_rewrite(provider: ModelProvider, query: str) -> dict[str, Any]:
    """Ask the configured model for one structured multimodal query rewrite."""

    system = (
        "You are a RAG-Anything query interpreter. "
        "Return JSON only with keys: "
        "rewritten_query (string), high_level_keys (array of strings), "
        "low_level_keys (array of strings), modality_hints (array of strings), "
        "entity_terms (array of strings). "
        "Use modality_hints like: text, table, figure, chart, equation, image. "
        "Keep rewritten_query concise and faithful."
    )
    try:
        raw = provider.chat(system, [{"role": "user", "content": query}])
    except Exception as exc:
        logger.warning("query:rewrite failed error=%s", type(exc).__name__)
        return {}
    return _parse_json_object(raw)


def normalize_rewrite_terms(value: object) -> list[str]:
    """Normalize one model-produced rewrite list without inventing values."""

    if isinstance(value, str):
        return [value.strip()] if value.strip() else []
    if not isinstance(value, list):
        return []
    return [item.strip() for item in value if isinstance(item, str) and item.strip()]


def build_query_rewrite(
    provider: ModelProvider,
    query: str,
    *,
    fast_mode: bool,
) -> QueryRewrite:
    """Return the optional rewrite and its normalized retrieval terms."""

    enabled = not fast_mode and is_multimodal_query(query)
    rewrite = request_query_rewrite(provider, query) if enabled else {}
    return {
        "enabled": enabled,
        "raw": rewrite,
        "high_level_keys": normalize_rewrite_terms(rewrite.get("high_level_keys")),
        "low_level_keys": normalize_rewrite_terms(rewrite.get("low_level_keys")),
        "modality_hints": normalize_rewrite_terms(rewrite.get("modality_hints")),
        "entity_terms": normalize_rewrite_terms(rewrite.get("entity_terms")),
        "rewritten_query": rewrite.get("rewritten_query"),
    }


def build_query_terms(
    rewritten_query: str,
    high_level_keys: list[str],
    low_level_keys: list[str],
    entity_terms: list[str],
) -> list[str]:
    """Build ordered, unique terms for graph and structural retrieval."""

    return list(
        dict.fromkeys(
            entity_terms
            + low_level_keys
            + high_level_keys
            + extract_terms(rewritten_query)
        )
    )


def _parse_json_object(text: str) -> dict[str, Any]:
    if not text:
        return {}
    try:
        payload = json.loads(text)
    except (TypeError, ValueError):
        match = re.search(r"\{.*\}", str(text), flags=re.DOTALL)
        if match is None:
            return {}
        try:
            payload = json.loads(match.group(0))
        except (TypeError, ValueError):
            return {}
    return payload if isinstance(payload, dict) else {}


__all__ = [
    "QueryRewrite",
    "build_query_rewrite",
    "build_query_terms",
    "is_multimodal_query",
    "normalize_rewrite_terms",
    "request_query_rewrite",
]
