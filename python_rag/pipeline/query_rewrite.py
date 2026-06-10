"""Query rewrite policy helpers."""
from __future__ import annotations

from typing import Any, Callable, Dict, List, Sequence

from pipeline.query_settings import int_env
from utils.text_preprocessor import _is_multimodal_query as _default_is_multimodal_query
from utils.text_preprocessor import _normalize_list as _default_normalize_list
from utils.text_preprocessor import _rewrite_query as _default_rewrite_query
from utils.text_preprocessor import _extract_terms as _default_extract_terms

NormalizeList = Callable[[Sequence[str] | None], List[str]]
RewriteQuery = Callable[[Any, str], Dict[str, Any]]
IsMultimodal = Callable[[str], bool]
ExtractTerms = Callable[[str], List[str]]


def normalize_int_env(name: str, default: int) -> int:
    """Read an integer setting from environment with fallback."""
    return int_env(name, default)


def build_query_rewrite(
    provider: Any,
    query: str,
    *,
    fast_mode: bool,
    is_multimodal_query: IsMultimodal = _default_is_multimodal_query,
    rewrite_query: RewriteQuery = _default_rewrite_query,
    normalize_list: NormalizeList = _default_normalize_list,
) -> Dict[str, Any]:
    """Build optional multimodal rewrite payload for a query."""
    rewrite_enabled = (not fast_mode) and is_multimodal_query(query)
    rewrite: Dict[str, Any] = {} if not rewrite_enabled else rewrite_query(provider, query)
    return {
        "enabled": rewrite_enabled,
        "raw": rewrite,
        "high_level_keys": normalize_list(rewrite.get("high_level_keys")),
        "low_level_keys": normalize_list(rewrite.get("low_level_keys")),
        "modality_hints": normalize_list(rewrite.get("modality_hints")),
        "entity_terms": normalize_list(rewrite.get("entity_terms")),
        "rewritten_query": rewrite.get("rewritten_query"),
    }


def build_query_terms(
    rewritten_query: str,
    high_level_keys: List[str],
    low_level_keys: List[str],
    entity_terms: List[str],
    *,
    extract_terms: ExtractTerms = _default_extract_terms,
) -> List[str]:
    """Build deduplicated query terms used for graph retrieval and structural search."""
    return list(
        dict.fromkeys(
            entity_terms + low_level_keys + high_level_keys + extract_terms(rewritten_query),
        )
    )
