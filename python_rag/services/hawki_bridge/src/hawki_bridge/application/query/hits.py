"""Pure hit-list operations used by query retrieval."""

from __future__ import annotations

import math
import re
from typing import TypeAlias


Hit: TypeAlias = dict[str, object]


def _payload(hit: Hit) -> dict[str, object]:
    payload = hit.get("payload")
    return payload if isinstance(payload, dict) else {}


def _score(hit: Hit) -> float:
    try:
        score = float(hit.get("score") or 0.0)
    except (TypeError, ValueError):
        return 0.0
    return score if math.isfinite(score) else 0.0


def hit_doc_id(hit: Hit) -> str:
    """Return the source-document identity used for graph score fusion."""
    return str(_payload(hit).get("doc_id") or "")


def hit_identity(hit: Hit) -> str:
    """Return the narrowest stable identity available for one retrieval hit."""
    payload = _payload(hit)
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
    sem_hits: list[Hit],
    struct_hits: list[Hit],
    *,
    sem_weight: float,
    str_weight: float,
) -> list[Hit]:
    structural_scores_by_doc: dict[str, float] = {}
    structural_representatives: dict[str, Hit] = {}
    for hit in struct_hits or []:
        doc_id = hit_doc_id(hit)
        if not doc_id:
            continue
        structural_scores_by_doc[doc_id] = structural_scores_by_doc.get(doc_id, 0.0) + (
            _score(hit) * str_weight
        )
        structural_representatives.setdefault(doc_id, hit)

    by_id: dict[str, Hit] = {}
    semantic_docs: set[str] = set()
    for hit in sem_hits or []:
        identity = hit_identity(hit)
        if not identity or identity in by_id:
            continue
        merged_hit = dict(hit)
        doc_id = hit_doc_id(hit)
        merged_hit["score"] = _score(
            merged_hit
        ) * sem_weight + structural_scores_by_doc.get(doc_id, 0.0)
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
    merged.sort(key=_score, reverse=True)
    return merged


def normalize_hit_scores(hits: list[Hit]) -> list[Hit]:
    """Make one retrieval stage comparable before cross-stage evidence fusion.

    A tied stage assigns full evidence to every hit. This is intentionally
    different from the reranker's neutral handling of tied blend signals.
    """
    if not hits:
        return []

    scores = [_score(hit) for hit in hits]
    lowest = min(scores)
    highest = max(scores)
    if math.isclose(lowest, highest):
        normalized_scores = [1.0] * len(hits)
    else:
        span = highest - lowest
        normalized_scores = [(score - lowest) / span for score in scores]

    normalized: list[Hit] = []
    for hit, score in zip(hits, normalized_scores):
        normalized_hit = dict(hit)
        normalized_hit["score"] = score
        normalized.append(normalized_hit)
    return normalized


def merge_hits(primary: list[Hit], secondary: list[Hit], limit: int) -> list[Hit]:
    """Merge retrieval stages after normalizing each stage's score scale."""
    by_id: dict[str, Hit] = {}
    evidence_by_id: dict[str, float] = {}
    representative_score_by_id: dict[str, float] = {}
    representative_raw_score_by_id: dict[str, float] = {}
    best_raw_score_by_id: dict[str, float] = {}

    stages = [stage for stage in (primary, secondary) if stage]
    for stage in stages:
        normalized_stage = normalize_hit_scores(stage)
        for raw_hit, normalized_hit in zip(stage, normalized_stage):
            identity = hit_identity(normalized_hit)
            if not identity:
                continue

            stage_score = _score(normalized_hit)
            raw_score = _score(raw_hit)
            evidence_by_id[identity] = evidence_by_id.get(identity, 0.0) + stage_score
            best_raw_score_by_id[identity] = max(
                best_raw_score_by_id.get(identity, raw_score),
                raw_score,
            )

            representative_score = representative_score_by_id.get(identity, -1.0)
            representative_raw_score = representative_raw_score_by_id.get(
                identity, -math.inf
            )
            if stage_score > representative_score or (
                math.isclose(stage_score, representative_score)
                and raw_score > representative_raw_score
            ):
                by_id[identity] = normalized_hit
                representative_score_by_id[identity] = stage_score
                representative_raw_score_by_id[identity] = raw_score

    stage_count = max(1, len(stages))
    merged: list[tuple[float, float, Hit]] = []
    for identity, hit in by_id.items():
        comparable_score = evidence_by_id[identity] / stage_count
        merged_hit = dict(hit)
        merged_hit["score"] = comparable_score
        merged.append((comparable_score, best_raw_score_by_id[identity], merged_hit))

    merged.sort(key=lambda item: (item[0], item[1]), reverse=True)
    return [hit for _score_value, _raw_score, hit in merged[:limit]]


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


def dedupe_hits_by_identity(hits: list[Hit]) -> list[Hit]:
    """Remove repeated chunks without collapsing distinct chunks from one document."""
    if not hits:
        return hits
    seen: set[str] = set()
    deduped: list[Hit] = []
    for hit in hits:
        identity = hit_identity(hit)
        if identity:
            if identity in seen:
                continue
            seen.add(identity)
        deduped.append(hit)
    return deduped


def dedupe_hits_by_title_or_url(hits: list[Hit]) -> list[Hit]:
    """Compatibility wrapper for callers using the former helper name."""
    return dedupe_hits_by_identity(hits)
