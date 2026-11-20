"""Collect and deduplicate graph lookup terms from retrieved evidence."""

from __future__ import annotations

from collections.abc import Iterable
from typing import Any

from hawki_bridge.application.query.lexical import document_terms


def terms_from_payload(payload: dict[str, Any]) -> list[str]:
    """Collect document terms from payload tags, titles, and source URLs."""
    terms: list[str] = []
    tags = payload.get("tags")
    if isinstance(tags, str):
        terms.extend(document_terms(tags).terms)
    elif isinstance(tags, list):
        for tag in tags:
            terms.extend(document_terms(str(tag)).terms)
    for key in ("title", "page_url", "source_url"):
        terms.extend(document_terms(str(payload.get(key) or "")).terms)
    return terms


def unique_terms(groups: Iterable[Iterable[object]]) -> list[str]:
    """Return stripped, ordered, deduplicated terms across candidate groups."""
    seen: set[str] = set()
    unique: list[str] = []
    for group in groups:
        for candidate in group:
            term = str(candidate or "").strip()
            if term and term not in seen:
                seen.add(term)
                unique.append(term)
    return unique
