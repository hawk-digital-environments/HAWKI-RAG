"""
Ingestion pipeline: chunk, enrich payload, write to Qdrant + Neo4j, summarize.
Extracted from rag_brain to keep concerns separated.
"""
from __future__ import annotations
import json
import os
import time
import logging
import signal
import threading
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple
from fastapi import HTTPException
from utils.text_preprocessor import ensure_tags, split_text
from vectorstore.qdrant_http import QdrantHTTP
from graph.neo4j_graph import Neo4jGraph
from graph.graph_utils import clean_triplets

logger = logging.getLogger(__name__)
_POINT_NAMESPACE = uuid.NAMESPACE_URL


class _GraphTimeout(Exception):
    pass


def _float_env(name: str, default: float) -> float:
    raw = os.environ.get(name)
    if raw is None or str(raw).strip() == "":
        return default
    try:
        return float(raw)
    except ValueError:
        return default


def _graph_failure_log_path(public_dir: Path) -> Path:
    env_path = os.environ.get("GRAPH_FAILURE_LOG", "").strip()
    if env_path:
        return Path(env_path)
    return public_dir.parent / "storage" / "logs" / "ingest_graph_failures.jsonl"


def _append_graph_failures(path: Path, failures: List[Dict[str, Any]]) -> None:
    if not failures:
        return
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        with path.open("a", encoding="utf-8") as handle:
            for item in failures:
                handle.write(json.dumps(item, ensure_ascii=False) + "\n")
    except Exception as exc:
        logger.warning("graph:failed to write failures log: %s", exc)

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
    logger.info(
        "ingest:start docs=%s dry_run=%s graph=%s graph_only=%s collection=%s",
        len(body.docs),
        dry_run,
        bool(body.graph),
        bool(getattr(body, "graph_only", False)),
        body.collection,
    )

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
        logger.debug("ingest:doc %s chunks=%s", d.id, len(chunks))
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
    logger.info("ingest:prepared docs=%s chunks=%s", doc_stats["processed_docs"], total_chunks)

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
        if body.graph and getattr(body, "dry_include_graph", False):
            provider = get_provider(body.provider)
            if body.embedding_model and hasattr(provider, "embed_model"):
                provider.embed_model = body.embedding_model.strip()
            triplets_by_doc, failures = _build_triplets_by_doc(chunk_records, body.graph_engine, rag_service, provider)
            graph_preview = _build_graph_preview(doc_stats, chunk_records, triplets_by_doc)
            summary["graph_preview"] = graph_preview
            preview_path = _write_graph_preview(graph_preview, public_dir)
            if preview_path:
                summary["graph_preview_file"] = str(preview_path)
            if failures:
                failure_path = _graph_failure_log_path(public_dir)
                _append_graph_failures(failure_path, failures)
                summary["graph_failures"] = len(failures)
                summary["graph_failures_file"] = str(failure_path)
        return {"ok": True, "dry_run": True, "summary": summary}

    provider = None
    points: List[Dict[str, Any]] = []
    vector_size: int | None = None
    qdrant_ms = None
    qdrant_write_start = None
    start = time.perf_counter()

    if body.graph or not getattr(body, "graph_only", False):
        provider = get_provider(body.provider)
        if body.embedding_model and hasattr(provider, "embed_model"):
            provider.embed_model = body.embedding_model.strip()

    if not getattr(body, "graph_only", False):
        logger.info("ingest:provider=%s embed_model=%s batch_size=%s", body.provider, getattr(provider, "embed_model", None), batch_size)
        points, vector_size = _build_points(chunk_records, provider)
        logger.info("ingest:qdrant points=%s vector_size=%s", len(points), vector_size)
        qdrant.ensure_collection(vector_size or 1024, distance=body.distance)
        qdrant_write_start = time.perf_counter()
        qdrant.upsert_points(points, batch_size=batch_size)
        qdrant_ms = (time.perf_counter() - qdrant_write_start) * 1000
        logger.info("ingest:qdrant upserted=%s ms=%.2f", len(points), qdrant_ms)
    if qdrant_write_start is not None:
        qdrant_ms = (time.perf_counter() - qdrant_write_start) * 1000

    neo4j_ms = None
    graph_preview = None
    if body.graph:
        graph = Neo4jGraph()
        try:
            graph_write_start = time.perf_counter()
            triplet_start = time.perf_counter()
            triplets_by_doc, failures = _build_triplets_by_doc(chunk_records, body.graph_engine, rag_service, provider)
            triplet_ms = (time.perf_counter() - triplet_start) * 1000
            total_triplets = sum(len(v) for v in triplets_by_doc.values())
            logger.info(
                "graph:extract done engine=%s triplets=%s docs=%s ms=%.2f",
                body.graph_engine,
                total_triplets,
                len(triplets_by_doc),
                triplet_ms,
            )
            graph_preview = _build_graph_preview(doc_stats, chunk_records, triplets_by_doc)
            for doc_id, triplets in triplets_by_doc.items():
                if triplets:
                    graph.upsert_triplets(triplets, doc_id=doc_id)
            neo4j_ms = (time.perf_counter() - graph_write_start) * 1000
            logger.info("graph:neo4j upsert docs=%s triplets=%s ms=%.2f", len(triplets_by_doc), total_triplets, neo4j_ms)
            if failures:
                failure_path = _graph_failure_log_path(public_dir)
                _append_graph_failures(failure_path, failures)
                logger.info("graph:failures count=%s file=%s", len(failures), failure_path)
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
    if body.graph and graph_preview:
        summary["graph_preview"] = graph_preview
        preview_path = _write_graph_preview(graph_preview, public_dir)
        if preview_path:
            summary["graph_preview_file"] = str(preview_path)

    try:
        public_dir.mkdir(parents=True, exist_ok=True)
        summary_path = public_dir / "ingest_summary.json"
        preview = json.dumps(summary, indent=2, ensure_ascii=False)
        summary_path.write_text(preview + "\n", encoding="utf-8")
        summary["summary_file"] = str(summary_path)
    except Exception as exc:
        summary["summary_file_error"] = str(exc)

    logger.info("ingest:done points=%s total_ms=%.2f", len(points), total_ms)
    return {"ok": True, "points": len(points), "summary": summary, "graph_only": bool(getattr(body, "graph_only", False))}


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


def _build_points(chunk_records: List[Dict[str, Any]], provider: Any) -> Tuple[List[Dict[str, Any]], int | None]:
    points: List[Dict[str, Any]] = []
    vector_size: int | None = None
    for rec in chunk_records:
        payload = dict(rec["payload"])
        try:
            vec = provider.embed(rec["content"])
        except Exception as exc:
            raise HTTPException(status_code=500, detail=f"Embedding failed: {exc}") from exc
        vector_size = vector_size or len(vec)
        point_key = f"{rec['doc_id']}:{payload.get('chunk_index', 0)}"
        # Qdrant point IDs must be UUID or integer; use deterministic UUID per chunk.
        point_id = str(uuid.uuid5(_POINT_NAMESPACE, point_key))
        points.append({
            "id": point_id,
            "vector": vec,
            "payload": payload,
        })
    logger.debug("ingest:points built=%s", len(points))
    return points, vector_size


def _build_triplets_by_doc(
    chunk_records: List[Dict[str, Any]],
    engine: str,
    rag_service: Any,
    provider: Any | None,
) -> tuple[Dict[str, List[tuple[str, str, str]]], List[Dict[str, Any]]]:
    if not chunk_records:
        return {}, []
    grouped: Dict[str, List[Dict[str, Any]]] = {}
    for rec in chunk_records:
        grouped.setdefault(rec["doc_id"], []).append(rec)
    provider_name = provider.__class__.__name__ if provider is not None else "none"
    rag_model = getattr(provider, "rag_model", None)
    embed_model = getattr(provider, "embed_model", None)
    doc_timeout_s = _float_env("GRAPH_DOC_TIMEOUT", 0.0)
    if doc_timeout_s > 0:
        logger.info("graph:extract doc_timeout=%.2fs", doc_timeout_s)
    logger.info(
        "graph:extract start engine=%s docs=%s chunks=%s provider=%s rag_model=%s embed_model=%s",
        engine,
        len(grouped),
        len(chunk_records),
        provider_name,
        rag_model,
        embed_model,
    )
    out: Dict[str, List[tuple[str, str, str]]] = {}
    failures: List[Dict[str, Any]] = []
    total_docs = len(grouped)
    doc_index = 0
    use_alarm = doc_timeout_s > 0 and threading.current_thread() is threading.main_thread()
    if doc_timeout_s > 0 and not use_alarm:
        logger.warning("graph:extract doc_timeout requested but not on main thread; timeout disabled")
    for doc_id, parts in grouped.items():
        doc_index += 1
        chunk_texts = [p.get("content") for p in parts if isinstance(p.get("content"), str) and p.get("content").strip()]
        if not chunk_texts:
            out[doc_id] = []
            continue
        first_payload = (parts[0] or {}).get("payload") if parts else {}
        file_path = None
        if isinstance(first_payload, dict):
            file_path = first_payload.get("page_url") or first_payload.get("source_url") or first_payload.get("file_path")
        total_chars = sum(len(t) for t in chunk_texts)
        logger.info(
            "graph:extract doc=%s idx=%s/%s chunks=%s chars=%s file=%s",
            doc_id,
            doc_index,
            total_docs,
            len(chunk_texts),
            total_chars,
            file_path or "-",
        )
        doc_start = time.perf_counter()
        triplets: List[tuple[str, str, str]] = []
        error: str | None = None
        if use_alarm:
            def _alarm_handler(signum, frame):
                raise _GraphTimeout("graph extraction timed out")
            previous_handler = signal.signal(signal.SIGALRM, _alarm_handler)
            signal.setitimer(signal.ITIMER_REAL, doc_timeout_s)
        try:
            triplets = rag_service.extract_triplets(
                "",
                engine,
                provider=provider,
                chunks=chunk_texts,
                doc_id=doc_id,
                file_path=file_path,
            )
        except _GraphTimeout as exc:
            error = str(exc)
        except Exception as exc:
            error = f"{type(exc).__name__}: {exc}"
        finally:
            if use_alarm:
                signal.setitimer(signal.ITIMER_REAL, 0)
                signal.signal(signal.SIGALRM, previous_handler)
        doc_ms = (time.perf_counter() - doc_start) * 1000
        if error:
            failures.append({
                "doc_id": str(doc_id),
                "file_path": file_path or "",
                "chunks": len(chunk_texts),
                "chars": total_chars,
                "error": error,
                "timestamp": utc_now_iso(),
            })
            logger.warning("graph:extract doc=%s failed=%s ms=%.2f", doc_id, error, doc_ms)
            triplets = []
        else:
            triplets = clean_triplets(triplets)
            logger.info("graph:extract doc=%s triplets=%s ms=%.2f", doc_id, len(triplets), doc_ms)
        out[doc_id] = triplets
    return out, failures


def _build_graph_preview(
    doc_stats: Dict[str, Any],
    chunk_records: List[Dict[str, Any]],
    triplets_by_doc: Dict[str, List[tuple[str, str, str]]],
) -> Dict[str, Any]:
    sources: Dict[str, Dict[str, str]] = {}
    for rec in chunk_records:
        doc_id = str(rec.get("doc_id"))
        if doc_id in sources:
            continue
        payload = rec.get("payload") or {}
        sources[doc_id] = {
            "title": str(payload.get("title") or ""),
            "page_url": str(payload.get("page_url") or payload.get("source_url") or ""),
            "source_url": str(payload.get("source_url") or ""),
        }

    chunks_per_doc = doc_stats.get("chunks_per_doc") or {}
    doc_limit = _int_env("RAG_GRAPH_PREVIEW_DOC_LIMIT", 0)
    per_doc: Dict[str, Any] = {}
    total_triplets = 0
    docs_with_triplets = 0

    for doc_id, triplets in triplets_by_doc.items():
        if doc_limit > 0 and len(per_doc) >= doc_limit:
            break
        triplet_count = len(triplets)
        total_triplets += triplet_count
        if triplet_count:
            docs_with_triplets += 1
        entry = {
            "chunks": int(chunks_per_doc.get(doc_id, 0)),
            "triplets": triplet_count,
        }
        entry.update(sources.get(doc_id, {}))
        if triplet_count or entry["chunks"]:
            per_doc[doc_id] = entry

    return {
        "timestamp": utc_now_iso(),
        "total_docs": int(doc_stats.get("processed_docs") or 0),
        "total_chunks": int(doc_stats.get("total_chunks") or 0),
        "docs_with_triplets": docs_with_triplets,
        "total_triplets": total_triplets,
        "per_doc": per_doc,
    }


def _write_graph_preview(graph_preview: Dict[str, Any], public_dir: Path) -> Optional[Path]:
    if not graph_preview:
        return None
    try:
        public_dir.mkdir(parents=True, exist_ok=True)
        preview_path = public_dir / "ingest_graph_preview.json"
        preview_path.write_text(json.dumps(graph_preview, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
        return preview_path
    except Exception as exc:
        logger.warning("ingest:failed to write graph preview: %s", exc)
        return None


def _int_env(name: str, default: int) -> int:
    try:
        return int(os.environ.get(name, default))
    except Exception:
        return default


def utc_now_iso() -> str:
    return datetime.now(tz=timezone.utc).isoformat()


def delete_document(doc_id: str) -> Dict[str, Any]:
    if not doc_id:
        raise HTTPException(status_code=400, detail="doc_id is required")
    return _delete_document_entries(doc_id)
