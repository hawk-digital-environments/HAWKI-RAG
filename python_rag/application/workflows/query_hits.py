"""Pure hit-list operations used by query retrieval."""
from __future__ import annotations

import re
from typing import Any, Dict, List


def hit_doc_id(hit: dict[str, Any]) -> str:
    """Return the source-document identity used for graph score fusion."""
    return str((hit.get("payload") or {}).get("doc_id") or "")


def hit_identity(hit: dict[str, Any]) -> str:
    """Return the narrowest stable identity available for one retrieval hit."""
    payload = hit.get("payload") or {}
    point_id = hit.get("id")
    if point_id is not None and str(point_id).strip():
        return f"point:{point_id}"

    doc_id = hit_doc_id(hit)
    chunk_id = payload.get("chunk_id")
    if chunk_id is not None and str(chunk_id).strip():
        return f"chunk:{doc_id}:{chunk_id}" if doc_id else f"chunk:{chunk_id}"

    relative_path = str(payload.get("relative_path") or "").strip()
    chunk_index = payload.get("chunk_index")
    if relative_path:
        return f"path:{relative_path}:{chunk_index if chunk_index is not None else ''}"

    if doc_id and chunk_index is not None:
        return f"chunk:{doc_id}:{chunk_index}"

    return f"doc:{doc_id}" if doc_id else ""


def fuse_hits(
    sem_hits: list[dict[str, Any]],
    struct_hits: list[dict[str, Any]],
    *,
    sem_weight: float,
    str_weight: float,
) -> list[dict[str, Any]]:
    structural_scores_by_doc: dict[str, float] = {}
    structural_representatives: dict[str, dict[str, Any]] = {}
    for hit in struct_hits or []:
        doc_id = hit_doc_id(hit)
        if not doc_id:
            continue
        structural_scores_by_doc[doc_id] = (
            structural_scores_by_doc.get(doc_id, 0.0)
            + (float(hit.get("score") or 0.0) * str_weight)
        )
        structural_representatives.setdefault(doc_id, hit)

    by_id: dict[str, dict[str, Any]] = {}
    semantic_docs: set[str] = set()
    for hit in sem_hits or []:
        identity = hit_identity(hit)
        if not identity or identity in by_id:
            continue
        merged_hit = dict(hit)
        doc_id = hit_doc_id(hit)
        merged_hit["score"] = (
            float(merged_hit.get("score") or 0.0) * sem_weight
            + structural_scores_by_doc.get(doc_id, 0.0)
        )
        by_id[identity] = merged_hit
        if doc_id:
            semantic_docs.add(doc_id)

    for doc_id, score in structural_scores_by_doc.items():
        if doc_id in semantic_docs:
            continue
        hit = structural_representatives[doc_id]
        identity = hit_identity(hit)
        if identity:
            merged_hit = dict(hit)
            merged_hit["score"] = score
            by_id[identity] = merged_hit
    merged = list(by_id.values())
    merged.sort(key=lambda item: float(item.get("score") or 0.0), reverse=True)
    return merged


def merge_hits(primary: list[dict[str, Any]], secondary: list[dict[str, Any]], limit: int) -> list[dict[str, Any]]:
    by_id: dict[str, dict[str, Any]] = {}
    for hit in primary or []:
        identity = hit_identity(hit)
        if identity:
            by_id[identity] = hit
    for hit in secondary or []:
        identity = hit_identity(hit)
        if not identity:
            continue
        # A secondary pass may use a different score scale. Preserve the
        # primary representation of an identical point instead of comparing
        # raw scores from unlike retrieval stages.
        if identity not in by_id:
            by_id[identity] = hit
    merged = list(by_id.values())
    merged.sort(key=lambda item: float(item.get("score") or 0.0), reverse=True)
    return merged[:limit]


def normalize_title(value: object) -> str:
    if not value:
        return ""
    return re.sub(r"\s+", " ", str(value)).strip().lower()


def normalize_url(value: object) -> str:
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


def dedupe_hits_by_title_or_url(hits: list[dict[str, Any]]) -> list[dict[str, Any]]:
    if not hits:
        return hits
    seen_titles: set[str] = set()
    seen_urls: set[str] = set()
    deduped: list[dict[str, Any]] = []
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
