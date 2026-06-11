"""Metadata normalization helpers for crawled ingest."""
from __future__ import annotations

from collections import Counter
import hashlib
import re
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional


_DEFAULT_STOPWORD_PATH = (
    Path(__file__).resolve().parents[1] / "config" / "german_stopwords_plain.txt"
)


def first_str(value: Any) -> Optional[str]:
    if isinstance(value, list) and value:
        value = value[0]
    if value is None:
        return None
    text = str(value).strip()
    if not text:
        return None
    if text.lower() in {"null", "none", "n/a", "undefined"}:
        return None
    return text


def resolve_date(meta: dict[str, Any], fallback_path: Optional[Path]) -> Optional[str]:
    date = first_str(meta.get("date"))
    if not date:
        date = first_str(meta.get("published_at") or meta.get("updated_at") or meta.get("modified_at"))
    if not date:
        try:
            if fallback_path and fallback_path.exists():
                date = datetime.fromtimestamp(fallback_path.stat().st_mtime, tz=timezone.utc).isoformat()
        except Exception:
            date = None
    return date


def to_array_list(value: Any) -> list[str]:
    if isinstance(value, str):
        return [value]
    if not isinstance(value, list):
        return []
    out: list[str] = []
    for item in value:
        text = first_str(item)
        if text:
            out.append(text)
    return out


def make_doc_id(page_url: Optional[str], rel_path: str) -> str:
    base = page_url or rel_path
    return hashlib.sha1(base.encode("utf-8", errors="ignore")).hexdigest()


def _flatten_keywords(raw: Any) -> Iterable[str]:
    if raw is None:
        return []
    if isinstance(raw, (list, tuple, set)):
        items: list[str] = []
        for item in raw:
            items.extend(list(_flatten_keywords(item)))
        return items
    if isinstance(raw, str):
        cleaned = re.sub(r"^[^\n:]{0,200}:\s*", "", raw)
        cleaned = re.sub(r"-\s*\d+\s*[\.-]?\s*", "\n", cleaned)
        cleaned = re.sub(r"\s*\d+\s*[\.\)\:\-]\s*", "\n", cleaned)
        parts: list[str] = []
        for line in re.split(r"[\r\n]+", cleaned):
            line = re.sub(r"^\s*[\-\*\•\u2022]?\s*", "", line)
            parts.extend(re.split(r"[,;]+", line))
        return [p.strip() for p in parts if p.strip()]
    return [str(raw).strip()]


def _load_stopwords() -> set[str]:
    try:
        content = _DEFAULT_STOPWORD_PATH.read_text(encoding="utf-8")
    except FileNotFoundError:
        return set()
    return {
        line.strip().lower()
        for line in content.splitlines()
        if line.strip() and not line.strip().startswith("#")
    }


STOPWORDS = _load_stopwords()


def normalize_tags(candidates: Iterable[str], limit: int = 10) -> list[str]:
    out: list[str] = []
    seen = set()
    for cand in candidates:
        cand = cand.replace("-", " ")
        cand = re.sub(r"[^\w\s]", " ", cand, flags=re.UNICODE)
        cand = re.sub(r"\s+", " ", cand).strip().lower()
        if not cand or len(cand) < 2:
            continue
        if cand not in seen:
            seen.add(cand)
            out.append(cand)
        if len(out) >= limit:
            break
    return out


def _fallback_keywords(text: str, limit: int = 10) -> list[str]:
    if not text:
        return []
    text = text.lower()
    words = re.findall(r"[a-z\u00c0-\u024f]+", text)
    counts: Counter[str] = Counter()
    for w in words:
        if len(w) < 4 or w in STOPWORDS:
            continue
        counts[w] += 1
    out: list[str] = []
    for word, _ in counts.most_common(limit * 2):
        if word not in out:
            out.append(word)
        if len(out) >= limit:
            break
    return out


def resolve_tags(meta: dict[str, Any], text: str) -> list[str]:
    raw_sources = [
        meta.get("tags"),
        meta.get("keywords"),
        meta.get("labels"),
    ]
    tags = normalize_tags([keyword for item in raw_sources if item for keyword in _flatten_keywords(item)], limit=10)
    if tags:
        return tags
    return _fallback_keywords(text, limit=10)


def title_from_markdown(markdown: str) -> Optional[str]:
    for line in markdown.splitlines():
        title = line.strip().lstrip("# ").strip()
        if title:
            return title[:200]
    return None
