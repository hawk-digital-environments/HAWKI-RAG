"""
Ingestion pipeline: chunk, enrich payload, write to Qdrant + Neo4j, summarize.
Extracted from rag_brain to keep concerns separated.
"""
from __future__ import annotations

import json
import os
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

from fastapi import HTTPException

from utils.text_preprocessor import ensure_tags, split_text
from vectorstore.qdrant_http import QdrantHTTP
from graph.neo4j_graph import Neo4jGraph


def _delete_document_entries(doc_id: str) -> Dict[str, Any]:
    qdrant = QdrantHTTP()
    qdrant_result = qdrant.delete_by_doc_id(doc_id)
    graph = Neo4jGraph()
    try:
        neo4j_result = graph.delete_by_doc_id(doc_id)
    finally:
        try:
            graph.close()
        except Exception:
            pass
    return {"qdrant": qdrant_result, "neo4j": neo4j_result}


def ingest_documents(
    body: Any,
    *,
    rag_service: Any,
    get_provider,
    public_dir: Path,
) -> Dict[str, Any]:
    dry_run = bool(body.dry_run)

    qdrant = QdrantHTTP()
    if body.collection:
        qdrant.collection = body.collection

    chunk_records: List[Dict[str, Any]] = []
    doc_stats: Dict[str, Any] = {
        "total_docs": len(body.docs),
        "processed_docs": 0,
        "skipped_docs": 0,
        "by_format": {},
        "doc_ids": [],
    }

    for d in body.docs:
        chunks = split_text(d.text, body.chunk_chars, body.chunk_overlap) or [d.text]
        doc_processed = False
        fmt: Optional[str] = None
        chunk_count = 0

        for idx, ch in enumerate(chunks):
            if not isinstance(ch, str) or not ch.strip():
                continue
            payload = dict(d.payload)
            payload.update({
                "content": ch,
                "chunk_index": idx,
                "source_format": payload.get("source_format", "text"),
            })
            payload["doc_id"] = str(d.id)
            payload.setdefault("component_type", "chunk")
            ensure_tags(payload, ch)
            chunk_records.append({
                "doc_id": str(d.id),
                "content": ch,
                "payload": payload,
            })
            doc_processed = True
            chunk_count += 1
            if not fmt:
                fmt = payload.get("source_format") or "unknown"

        if doc_processed:
            doc_stats["processed_docs"] += 1
            doc_stats["doc_ids"].append(str(d.id))
            fmt_key = fmt or "unknown"
            by_fmt = doc_stats["by_format"]
            by_fmt[fmt_key] = by_fmt.get(fmt_key, 0) + 1
            if chunk_count:
                chunks_map = doc_stats.setdefault("chunks_per_doc", {})
                chunks_map[str(d.id)] = chunk_count
        else:
            doc_stats["skipped_docs"] += 1

    total_chunks = len(chunk_records)
    doc_stats["total_chunks"] = total_chunks

    if total_chunks == 0:
        raise HTTPException(400, detail="No valid content to ingest")

    batch_size = max(1, int(body.batch_size or 64))

    if dry_run:
        summary = _build_summary(
            doc_stats,
            total_chunks=total_chunks,
            batch_size=batch_size,
            collection=qdrant.collection,
            graph_enabled=bool(body.graph),
            estimate_only=True,
        )
        return {"ok": True, "dry_run": True, "summary": summary}

    start = time.perf_counter()
    points = _build_points(chunk_records, body)
    qdrant_write_start = time.perf_counter()
    qdrant.upsert_points(points, batch_size=batch_size)
    qdrant_ms = (time.perf_counter() - qdrant_write_start) * 1000

    neo4j_ms = None
    if body.graph:
        graph = Neo4jGraph()
        try:
            graph_write_start = time.perf_counter()
            triplets = _build_triplets(chunk_records, body.graph_engine)
            if triplets:
                graph.upsert_triplets(triplets)
            neo4j_ms = (time.perf_counter() - graph_write_start) * 1000
        finally:
            try:
                graph.close()
            except Exception:
                pass

    total_ms = (time.perf_counter() - start) * 1000

    summary = _build_summary(
        doc_stats,
        total_chunks=total_chunks,
        batch_size=batch_size,
        collection=qdrant.collection,
        graph_enabled=bool(body.graph),
        qdrant_ms=qdrant_ms,
        neo4j_ms=neo4j_ms,
        total_ms=total_ms,
    )

    try:
        public_dir.mkdir(parents=True, exist_ok=True)
        summary_path = public_dir / "ingest_summary.json"
        preview = json.dumps(summary, indent=2, ensure_ascii=False)
        summary_path.write_text(preview + "\n", encoding="utf-8")
        summary["summary_file"] = str(summary_path)
    except Exception as exc:
        summary["summary_file_error"] = str(exc)

    return {"ok": True, "points": len(points), "summary": summary}


def _build_summary(
    doc_stats: Dict[str, Any],
    *,
    total_chunks: int,
    batch_size: int,
    collection: str,
    graph_enabled: bool,
    estimate_only: bool = False,
    qdrant_ms: float | None = None,
    neo4j_ms: float | None = None,
    total_ms: float | None = None,
) -> Dict[str, Any]:
    summary: Dict[str, Any] = {
        "timestamp": utc_now_iso(),
        "estimate_only": estimate_only,
        "planned_points": total_chunks,
        "documents": doc_stats,
        "qdrant_preview": {
            "collection": collection or "(server default)",
            "batch_size": batch_size,
            "planned_batches": (total_chunks + batch_size - 1) // batch_size if total_chunks else 0,
            "planned_points": total_chunks,
            "elapsed_ms": qdrant_ms,
        },
        "graph": {
            "enabled": graph_enabled,
            "elapsed_ms": neo4j_ms,
        },
        "total_ms": total_ms,
        "dry_run": estimate_only,
    }
    return summary


def _build_points(chunk_records: List[Dict[str, Any]], body: Any) -> List[Dict[str, Any]]:
    provider = None
    if not body.dry_run:
        provider = body.provider
    points: List[Dict[str, Any]] = []
    for rec in chunk_records:
        payload = dict(rec["payload"])
        vec = None
        if provider:
            vec = body.provider_obj.embed(rec["content"]) if hasattr(body, "provider_obj") else None
        points.append({
            "id": rec["doc_id"],
            "vector": vec,
            "payload": payload,
        })
    return points


def _build_triplets(chunk_records: List[Dict[str, Any]], engine: str) -> List[tuple[str, str, str]]:
    # Placeholder: actual triplet extraction stays in rag_brain; call through rag_service if needed.
    if not chunk_records:
        return []
    return []


def utc_now_iso() -> str:
    return datetime.now(tz=timezone.utc).isoformat()


def delete_document(doc_id: str) -> Dict[str, Any]:
    if not doc_id:
        raise HTTPException(status_code=400, detail="doc_id is required")
    return _delete_document_entries(doc_id)
