from __future__ import annotations

import logging
import re
from pathlib import Path
from typing import Any

from infrastructure.raganything.fallback_parser import Triplet, dedupe_triplets, is_junk_graph_label, strip_control_chars

logger = logging.getLogger(__name__)


def edge_relation_label(edge: dict[str, Any]) -> str:
    raw = edge.get("keywords") or edge.get("description") or edge.get("content") or "RELATED_TO"
    if isinstance(raw, (list, tuple)):
        raw = ", ".join(str(x) for x in raw if str(x).strip())
    rel = strip_control_chars(str(raw)).replace("\n", " ").strip()
    if "\t" in rel:
        rel = rel.split("\t", 1)[0].strip()
    if "," in rel:
        rel = rel.split(",", 1)[0].strip()
    rel = re.sub(r"\s+", " ", rel)
    if len(rel) > 120:
        rel = rel[:120].rstrip()
    return rel or "RELATED_TO"


def triplets_from_raganything_edges(
    *,
    edges: list[dict[str, Any]],
    file_ref: str,
    created_at_floor: int,
    graph_debug: bool = False,
) -> list[Triplet]:
    file_edges: list[dict[str, Any]] = []
    recent_file_edges: list[dict[str, Any]] = []
    file_ref_norm = _norm_path(file_ref)
    file_ref_name = Path(file_ref_norm).name
    for edge in edges or []:
        if not isinstance(edge, dict):
            continue
        created_at = _edge_created_at(edge)
        edge_file = _norm_path(edge.get("file_path"))
        if edge_file != file_ref_norm and Path(edge_file).name != file_ref_name:
            continue
        file_edges.append(edge)
        if created_at >= max(0, created_at_floor - 1):
            recent_file_edges.append(edge)

    # Prefer edges produced by the current insert call. If RAG-Anything deduplicated the
    # document, use only edges already tied to the same unique file reference.
    selected = recent_file_edges or file_edges
    if graph_debug:
        logger.debug(
            "graph:raganything export edges total=%s file=%s file_edges=%s recent=%s selected=%s",
            len(edges or []),
            file_ref,
            len(file_edges),
            len(recent_file_edges),
            len(selected),
        )

    triplets: list[Triplet] = []
    for edge in selected:
        src = str(edge.get("source") or edge.get("src_id") or "").strip()
        tgt = str(edge.get("target") or edge.get("tgt_id") or "").strip()
        if not src or not tgt:
            continue
        if is_junk_graph_label(src) or is_junk_graph_label(tgt):
            continue
        triplets.append((src, edge_relation_label(edge), tgt))
    return dedupe_triplets(triplets)


def _edge_created_at(edge: dict[str, Any]) -> int:
    for key in ("created_at", "__created_at__", "create_time", "update_time"):
        try:
            return int(edge.get(key))
        except Exception:
            continue
    return 0


def _norm_path(value: Any) -> str:
    return str(value or "").replace("\\", "/").strip()
