"""Context summary preparation for query responses."""
from __future__ import annotations

from typing import Any, Dict, List, Tuple

from utils.text_preprocessor import _estimate_tokens, _sanitize_context_snippet, _truncate_to_tokens


def prepare_context_summaries(
    hits: List[Dict[str, Any]],
    *,
    max_docs: int,
    max_tokens: int,
) -> Tuple[List[Dict[str, Any]], List[int], int]:
    summaries: List[Dict[str, Any]] = []
    trimmed: List[int] = []
    used_tokens = 0

    for idx, hit in enumerate(hits[: max_docs], start=1):
        payload = hit.get("payload") or {}
        title_raw = payload.get("title") or payload.get("page_title") or "Untitled"
        url_raw = payload.get("page_url") or payload.get("source_url") or ""
        snippet_raw = (payload.get("snippet") or payload.get("content") or "")[:1200]
        component_type = payload.get("component_type") or payload.get("type") or "chunk"
        source_format = payload.get("source_format") or payload.get("format")

        title = _sanitize_context_snippet(title_raw) or "Untitled"
        url = _sanitize_context_snippet(url_raw)
        clean_snippet = _sanitize_context_snippet(snippet_raw)
        base_tokens = _estimate_tokens(title) + _estimate_tokens(url)

        remaining = max_tokens - used_tokens - base_tokens
        if remaining <= 0:
            trimmed.append(idx)
            continue

        snippet = _truncate_to_tokens(clean_snippet, remaining)
        if snippet != clean_snippet:
            trimmed.append(idx)
        if not snippet:
            snippet = "[Excerpt removed by content safety]"

        doc_tokens = base_tokens + _estimate_tokens(snippet)
        used_tokens += doc_tokens
        summaries.append({
            "idx": idx,
            "title": title,
            "url": url,
            "snippet": snippet,
            "component_type": component_type,
            "source_format": source_format,
        })
        if used_tokens >= max_tokens:
            break

    return summaries, trimmed, used_tokens
