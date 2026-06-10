"""Pure hit-list operations used by query retrieval."""
from __future__ import annotations

import re
from typing import Any, Dict, List


def hit_doc_id(hit: Dict[str, Any]) -> str:
    return str((hit.get("payload") or {}).get("doc_id") or hit.get("id") or "")


def fuse_hits(
    sem_hits: List[Dict[str, Any]],
    struct_hits: List[Dict[str, Any]],
    *,
    sem_weight: float,
    str_weight: float,
) -> List[Dict[str, Any]]:
    by_id: Dict[str, Dict[str, Any]] = {}
    for hit in sem_hits or []:
        doc_id = hit_doc_id(hit)
        if not doc_id:
            continue
        merged_hit = dict(hit)
        merged_hit["score"] = float(merged_hit.get("score") or 0.0) * sem_weight
        by_id[doc_id] = merged_hit
    for hit in struct_hits or []:
        doc_id = hit_doc_id(hit)
        if not doc_id:
            continue
        score = float(hit.get("score") or 0.0) * str_weight
        if doc_id in by_id:
            by_id[doc_id]["score"] = (by_id[doc_id]["score"] or 0.0) + score
        else:
            merged_hit = dict(hit)
            merged_hit["score"] = score
            by_id[doc_id] = merged_hit
    merged = list(by_id.values())
    merged.sort(key=lambda item: float(item.get("score") or 0.0), reverse=True)
    return merged


def merge_hits(primary: List[Dict[str, Any]], secondary: List[Dict[str, Any]], limit: int) -> List[Dict[str, Any]]:
    by_id: Dict[str, Dict[str, Any]] = {}
    for hit in primary or []:
        doc_id = hit_doc_id(hit)
        if doc_id:
            by_id[doc_id] = hit
    for hit in secondary or []:
        doc_id = hit_doc_id(hit)
        if not doc_id:
            continue
        if doc_id not in by_id:
            by_id[doc_id] = hit
    merged = list(by_id.values())
    merged.sort(key=lambda item: float(item.get("score") or 0.0), reverse=True)
    return merged[:limit]


def normalize_title(value: Any) -> str:
    if not value:
        return ""
    return re.sub(r"\s+", " ", str(value)).strip().lower()


def normalize_url(value: Any) -> str:
    if isinstance(value, list):
        value = value[0] if value else ""
    if not value:
        return ""
    url = str(value).strip().lower()
    if not url:
        return ""
    url = re.sub(r"^https?://", "", url)
    url = re.sub(r"^www\.", "", url)
    if "#" in url:
        url = url.split("#", 1)[0]
    return url.rstrip("/")


def dedupe_hits_by_title_or_url(hits: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    if not hits:
        return hits
    seen_titles: set[str] = set()
    seen_urls: set[str] = set()
    deduped: List[Dict[str, Any]] = []
    for hit in hits:
        payload = hit.get("payload") or {}
        title = normalize_title(payload.get("title"))
        page_url = normalize_url(payload.get("page_url"))
        source_url = normalize_url(payload.get("source_url"))
        url_key = page_url or source_url
        if title and title in seen_titles:
            continue
        if url_key and url_key in seen_urls:
            continue
        if title:
            seen_titles.add(title)
        if url_key:
            seen_urls.add(url_key)
        deduped.append(hit)
    return deduped
