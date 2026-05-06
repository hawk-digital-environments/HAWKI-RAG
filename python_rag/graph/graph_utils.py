"""
Graph helpers: structural expansion and Neo4j related utilities.
"""
from __future__ import annotations

import os
import logging
import re
import time
from typing import Any, Dict, List, Iterable, Tuple

from graph.neo4j_graph import Neo4jGraph

logger = logging.getLogger(__name__)
GRAPH_PERF_LOG = os.environ.get("GRAPH_PERF_LOG", "").strip().lower() in ("1", "true", "yes")


def _perf_log(msg: str, *args: Any) -> None:
    if GRAPH_PERF_LOG:
        logger.info(msg, *args)

_IMAGE_EXT_RE = re.compile(r"\.(png|jpe?g|gif|webp|svg)(?:\\?|#|$)", re.IGNORECASE)
_PAGE_MARK_RE = re.compile(r"^(?:p|page)\\s*\\d+$", re.IGNORECASE)


def _normalize_text(value: Any) -> str:
    if value is None:
        return ""
    return " ".join(str(value).split())


def _looks_like_image_ref(value: str) -> bool:
    lowered = value.lower()
    if "/images" in lowered or "/images_pdf" in lowered:
        return True
    return bool(_IMAGE_EXT_RE.search(lowered))


def _looks_like_page_marker(value: str) -> bool:
    return bool(_PAGE_MARK_RE.match(value.strip()))


def _is_noise_entity(value: str) -> bool:
    if not value:
        return True
    compact = value.strip()
    if compact in {"[]", "[ ]"}:
        return True
    if _looks_like_page_marker(compact):
        return True
    if _looks_like_image_ref(compact):
        return True
    return False


def clean_triplets(triplets: Iterable[Tuple[str, str, str]]) -> List[Tuple[str, str, str]]:
    start = time.perf_counter()
    try:
        input_count = len(triplets)  # type: ignore[arg-type]
    except Exception:
        input_count = -1
    _perf_log(
        "perf:graph graph.graph_utils.clean_triplets start input=%s",
        input_count if input_count >= 0 else "unknown",
    )
    cleaned: List[Tuple[str, str, str]] = []
    seen = set()
    dropped = 0
    for s, r, o in triplets:
        subj = _normalize_text(s)
        rel = _normalize_text(r)
        obj = _normalize_text(o)
        if not subj or not rel or not obj:
            dropped += 1
            continue
        if _is_noise_entity(subj) or _is_noise_entity(obj):
            dropped += 1
            continue
        key = (subj, rel, obj)
        if key in seen:
            dropped += 1
            continue
        seen.add(key)
        cleaned.append((subj, rel, obj))
    if dropped:
        logger.info("graph:triplets cleanup dropped=%s kept=%s", dropped, len(cleaned))
    _perf_log(
        "perf:graph graph.graph_utils.clean_triplets done input=%s kept=%s dropped=%s ms=%.2f",
        input_count if input_count >= 0 else "unknown",
        len(cleaned),
        dropped,
        (time.perf_counter() - start) * 1000,
    )
    return cleaned

def fetch_related_terms(terms: List[str], limit: int = 30) -> List[Dict[str, str]]:
    if not terms:
        return []
    g = Neo4jGraph()
    try:
        results = g.fetch_related(terms, limit=limit)
        logger.debug("graph:fetch_related terms=%s results=%s", len(terms), len(results))
        return results
    except Exception:
        return []
    finally:
        try:
            g.close()
        except Exception:
            pass


def structural_limit(top_k: int) -> int:
    return int(os.environ.get("RAG_STRUCTURAL_LIMIT", max(top_k * 2, 12)))


def structural_hops(default_hops: int = 2) -> int:
    try:
        return int(os.environ.get("RAG_STRUCTURAL_HOPS", str(default_hops)))
    except Exception:
        return default_hops


def build_structural_hits(
    terms: List[str],
    *,
    limit: int,
    hops: int,
    include_rel_match: bool = False,
) -> List[Dict[str, Any]]:
    if not terms:
        return []
    g = Neo4jGraph()
    try:
        rows = g.search_structural(terms, limit=limit, hops=hops, include_rel_match=include_rel_match)
    except Exception:
        rows = []
    finally:
        try:
            g.close()
        except Exception:
            pass

    hits: List[Dict[str, Any]] = []
    for row in rows:
        s = row.get("subject") or ""
        r = row.get("relation") or ""
        o = row.get("object") or ""
        hops_used = int(row.get("hops") or 1)
        doc_id = row.get("doc_id")
        content = f"{s} -{r}-> {o}".strip(" -")
        score = 1.0 / max(1, hops_used)
        hits.append(
            {
                "id": f"neo4j:{s}:{r}:{o}:{doc_id or ''}",
                "score": score,
                "payload": {
                    "component_type": "relation",
                    "subject": s,
                    "relation": r,
                    "object": o,
                    "doc_id": doc_id,
                    "content": content,
                    "title": "Graph relation",
                },
                "source": "neo4j",
            }
        )
    logger.debug("graph:structural_hits terms=%s hits=%s", len(terms), len(hits))
    return hits
