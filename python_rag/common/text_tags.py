"""Keyword and tag normalization helpers."""

from __future__ import annotations

import re
from collections import Counter
from typing import Any

from common.text_terms import STOPWORDS


def flatten_keywords(raw: Any) -> list[str]:
    """Flatten nested keyword values into comma/list-like string candidates."""

    if raw is None:
        return []
    if isinstance(raw, (list, tuple, set)):
        out: list[str] = []
        for item in raw:
            out.extend(flatten_keywords(item))
        return out
    value = str(raw)
    cleaned = re.sub(r"^[^\n:]{0,200}:\s*", "", value)
    cleaned = re.sub(r"-\s*\d+\s*[\.-]?\s*", "\n", cleaned)
    cleaned = re.sub(r"\s*\d+\s*[\.\)\:\-]\s*", "\n", cleaned)
    parts: list[str] = []
    for line in re.split(r"[\r\n]+", cleaned):
        line = re.sub(r"^\s*[\-\*\u2022]?\s*", "", line)
        parts.extend(re.split(r"[,;]+", line))
    return [part.strip() for part in parts if part.strip()]


def normalize_tags(candidates: list[str], limit: int = 10) -> list[str]:
    """Normalize and deduplicate tag candidates."""

    out: list[str] = []
    seen: set[str] = set()
    for candidate in candidates:
        candidate = candidate.replace("-", " ")
        candidate = re.sub(r"[^\w\s]", " ", candidate, flags=re.UNICODE)
        candidate = re.sub(r"\s+", " ", candidate).strip().lower()
        if not candidate or len(candidate) < 2:
            continue
        if candidate not in seen:
            seen.add(candidate)
            out.append(candidate)
        if len(out) >= limit:
            break
    return out


def fallback_tags(text: str, limit: int = 10) -> list[str]:
    """Generate tag candidates from repeated non-stopword body terms."""

    if not text:
        return []
    words = re.findall(r"[a-z\u00c0-\u024f]+", text.lower())
    counts: Counter[str] = Counter()
    for word in words:
        if len(word) < 4 or word in STOPWORDS:
            continue
        counts[word] += 1
    tags: list[str] = []
    for word, _count in counts.most_common(limit * 2):
        if word not in tags:
            tags.append(word)
        if len(tags) >= limit:
            break
    return tags


__all__ = ["fallback_tags", "flatten_keywords", "normalize_tags"]
