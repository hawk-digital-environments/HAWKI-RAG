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
from pipeline.observability import pipeline_log
from pipeline.validation import normalize_ingest_metadata, validate_ingest_document

logger = logging.getLogger(__name__)
_POINT_NAMESPACE = uuid.NAMESPACE_URL
GRAPH_DEBUG = os.environ.get("GRAPH_DEBUG", "").strip().lower() in ("1", "true", "yes")
GRAPH_PERF_LOG = os.environ.get("GRAPH_PERF_LOG", "").strip().lower() in ("1", "true", "yes")


def _perf_log(msg: str, *args: Any) -> None:
    if GRAPH_PERF_LOG:
        logger.info(msg, *args)


def _infer_job_id(body: Any, docs: List[Any]) -> str | None:
    body_job_id = getattr(body, "job_id", None)
    if body_job_id:
        return str(body_job_id)
    for doc in docs:
        payload = getattr(doc, "payload", None) or {}
        if isinstance(payload, dict):
            job_id = payload.get("job_id") or payload.get("trace_id")
            if job_id:
                return str(job_id)
    return None


def _doc_job_id(default_job_id: str | None, doc: Any) -> str | None:
    payload = getattr(doc, "payload", None) or {}
    if isinstance(payload, dict):
        return str(payload.get("job_id") or payload.get("trace_id") or default_job_id or "") or None
    return default_job_id


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


def _run_graph_extract_with_timeout(
    func,
    timeout_s: float,
    *,
    allow_alarm: bool,
) -> tuple[List[tuple[str, str, str]], str | None]:
    if timeout_s <= 0:
        try:
            return func(), None
        except Exception as exc:
            return [], f"{type(exc).__name__}: {exc}"

    if allow_alarm:
        def _alarm_handler(signum, frame):
            raise _GraphTimeout("graph extraction timed out")
        previous_handler = signal.signal(signal.SIGALRM, _alarm_handler)
        signal.setitimer(signal.ITIMER_REAL, timeout_s)
        try:
            return func(), None
        except _GraphTimeout as exc:
            return [], str(exc)
        except Exception as exc:
            return [], f"{type(exc).__name__}: {exc}"
        finally:
            signal.setitimer(signal.ITIMER_REAL, 0)
            signal.signal(signal.SIGALRM, previous_handler)

    result: Dict[str, Any] = {"done": False, "value": [], "error": None}

    def _target():
        try:
            result["value"] = func()
        except Exception as exc:
            result["error"] = exc
        finally:
            result["done"] = True

    thread = threading.Thread(target=_target, daemon=True)
    thread.start()
    thread.join(timeout_s)
    if not result["done"]:
        return [], "graph extraction timed out"
    if result["error"] is not None:
        exc = result["error"]
        return [], f"{type(exc).__name__}: {exc}"
    return result["value"], None

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
    docs = list(getattr(body, "docs", []) or [])
    run_job_id = _infer_job_id(body, docs)
    pipeline_log(
        logger,
        logging.INFO,
        stage="ingest",
        status="started",
        job_id=run_job_id,
        total_docs=len(docs),
        dry_run=dry_run,
        graph=bool(body.graph),
        graph_only=bool(getattr(body, "graph_only", False)),
        collection=body.collection,
        neo4j_database=getattr(body, "neo4j_database", None),
    )
    logger.info(
        "ingest:start docs=%s dry_run=%s graph=%s graph_only=%s collection=%s neo4j_database=%s",
        len(docs),
        dry_run,
        bool(body.graph),
        bool(getattr(body, "graph_only", False)),
        body.collection,
        getattr(body, "neo4j_database", None),
    )
    if GRAPH_DEBUG:
        logger.debug(
            "ingest:options provider=%s graph_engine=%s chunk_chars=%s chunk_overlap=%s batch_size=%s distance=%s",
            getattr(body, "provider", None),
            getattr(body, "graph_engine", None),
            getattr(body, "chunk_chars", None),
            getattr(body, "chunk_overlap", None),
            getattr(body, "batch_size", None),
            getattr(body, "distance", None),
        )

    qdrant = QdrantHTTP()
    if body.collection:
        qdrant.collection = body.collection

    chunk_records: List[Dict[str, Any]] = []
    doc_stats: Dict[str, Any] = {
        "total_docs": len(docs),
        "processed_docs": 0,
        "skipped_docs": 0,
        "by_format": {},
        "doc_ids": [],
        "validation_failures": [],
        "validation_warnings": [],
    }

    for d in docs:
        doc_id = str(getattr(d, "id", ""))
        doc_job_id = _doc_job_id(run_job_id, d)
        errors, warnings = validate_ingest_document(d)
        if errors:
            message = "; ".join(errors)
            doc_stats["skipped_docs"] += 1
            doc_stats["validation_failures"].append({"doc_id": doc_id, "errors": errors})
            pipeline_log(
                logger,
                logging.WARNING,
                stage="ingest",
                status="skipped",
                job_id=doc_job_id,
                doc_id=doc_id,
                error_message=message,
                reason="validation_failed",
                errors=errors,
            )
            continue

        normalized_payload = normalize_ingest_metadata(d)
        if warnings:
            doc_stats["validation_warnings"].append({"doc_id": doc_id, "warnings": warnings})
            pipeline_log(
                logger,
                logging.WARNING,
                stage="ingest",
                status="partial",
                job_id=doc_job_id,
                doc_id=doc_id,
                warnings=warnings,
                title=normalized_payload.get("title"),
                source_url=normalized_payload.get("source_url") or normalized_payload.get("page_url"),
            )

        chunks = split_text(d.text, body.chunk_chars, body.chunk_overlap) or [d.text]
        logger.debug("ingest:doc %s chunks=%s", doc_id, len(chunks))
        if GRAPH_DEBUG:
            logger.debug("ingest:doc %s text_len=%s", doc_id, len(d.text or ""))
        doc_processed = False
        fmt: Optional[str] = None
        chunk_count = 0

        for idx, ch in enumerate(chunks):
            if not isinstance(ch, str) or not ch.strip():
                continue
            payload = dict(normalized_payload)
            payload.update({
                "content": ch,
                "chunk_index": idx,
                "source_format": payload.get("source_format", "text"),
            })
            payload["doc_id"] = doc_id
            payload.setdefault("component_type", "chunk")
            ensure_tags(payload, ch)
            chunk_records.append({
                "doc_id": doc_id,
                "content": ch,
                "payload": payload,
            })
            doc_processed = True
            chunk_count += 1
            if not fmt:
                fmt = payload.get("source_format") or "unknown"

        if doc_processed:
            doc_stats["processed_docs"] += 1
            doc_stats["doc_ids"].append(doc_id)
            fmt_key = fmt or "unknown"
            by_fmt = doc_stats["by_format"]
            by_fmt[fmt_key] = by_fmt.get(fmt_key, 0) + 1
            if chunk_count:
                chunks_map = doc_stats.setdefault("chunks_per_doc", {})
                chunks_map[doc_id] = chunk_count
            pipeline_log(
                logger,
                logging.INFO,
                stage="ingest",
                status="success",
                job_id=doc_job_id,
                doc_id=doc_id,
                chunks=chunk_count,
                source_format=fmt_key,
                title=normalized_payload.get("title"),
                source_url=normalized_payload.get("source_url") or normalized_payload.get("page_url"),
            )
        else:
            doc_stats["skipped_docs"] += 1
            pipeline_log(
                logger,
                logging.WARNING,
                stage="ingest",
                status="skipped",
                job_id=doc_job_id,
                doc_id=doc_id,
                error_message="No non-empty chunks generated.",
                reason="empty_chunks",
            )

    total_chunks = len(chunk_records)
    doc_stats["total_chunks"] = total_chunks

    if total_chunks == 0:
        pipeline_log(
            logger,
            logging.ERROR,
            stage="ingest",
            status="failed",
            job_id=run_job_id,
            error_message="No valid content to ingest",
            validation_failures=doc_stats.get("validation_failures", []),
            skipped_docs=doc_stats["skipped_docs"],
        )
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
            triplets_by_doc, failures = _build_triplets_by_doc(
                chunk_records,
                body.graph_engine,
                rag_service,
                provider,
                neo4j_database=getattr(body, "neo4j_database", None),
            )
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
                for failure in failures:
                    pipeline_log(
                        logger,
                        logging.WARNING,
                        stage="ingest",
                        status="partial",
                        job_id=run_job_id,
                        doc_id=failure.get("doc_id"),
                        pipeline_stage="graph_extract",
                        error_message=failure.get("error"),
                        file_path=failure.get("file_path"),
                    )
        pipeline_log(
            logger,
            logging.INFO,
            stage="ingest",
            status="success",
            job_id=run_job_id,
            processed_docs=doc_stats["processed_docs"],
            skipped_docs=doc_stats["skipped_docs"],
            total_chunks=total_chunks,
            dry_run=True,
        )
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
        pipeline_log(
            logger,
            logging.INFO,
            stage="ingest",
            status="success",
            job_id=run_job_id,
            pipeline_stage="embedding",
            points=len(points),
            vector_size=vector_size,
        )
        logger.info("ingest:qdrant points=%s vector_size=%s", len(points), vector_size)
        qdrant.ensure_collection(vector_size or 1024, distance=body.distance)
        qdrant_write_start = time.perf_counter()
        qdrant.upsert_points(points, batch_size=batch_size)
        qdrant_ms = (time.perf_counter() - qdrant_write_start) * 1000
        pipeline_log(
            logger,
            logging.INFO,
            stage="ingest",
            status="success",
            job_id=run_job_id,
            pipeline_stage="index_vector",
            points=len(points),
            elapsed_ms=round(qdrant_ms, 2),
            collection=qdrant.collection,
        )
        logger.info("ingest:qdrant upserted=%s ms=%.2f", len(points), qdrant_ms)
    if qdrant_write_start is not None:
        qdrant_ms = (time.perf_counter() - qdrant_write_start) * 1000

    neo4j_ms = None
    graph_preview = None
    if body.graph:
        graph = Neo4jGraph(database=getattr(body, "neo4j_database", None))
        try:
            graph_write_start = time.perf_counter()
            triplet_start = time.perf_counter()
            triplets_by_doc, failures = _build_triplets_by_doc(
                chunk_records,
                body.graph_engine,
                rag_service,
                provider,
                graph=graph,
                neo4j_database=getattr(body, "neo4j_database", None),
            )
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
            neo4j_ms = (time.perf_counter() - graph_write_start) * 1000
            logger.info("graph:neo4j upsert docs=%s triplets=%s ms=%.2f", len(triplets_by_doc), total_triplets, neo4j_ms)
            if failures:
                failure_path = _graph_failure_log_path(public_dir)
                _append_graph_failures(failure_path, failures)
                for failure in failures:
                    pipeline_log(
                        logger,
                        logging.WARNING,
                        stage="ingest",
                        status="partial",
                        job_id=run_job_id,
                        doc_id=failure.get("doc_id"),
                        pipeline_stage="graph_extract",
                        error_message=failure.get("error"),
                        file_path=failure.get("file_path"),
                    )
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

    pipeline_log(
        logger,
        logging.INFO,
        stage="ingest",
        status="success",
        job_id=run_job_id,
        processed_docs=doc_stats["processed_docs"],
        skipped_docs=doc_stats["skipped_docs"],
        points=len(points),
        total_chunks=total_chunks,
        elapsed_ms=round(total_ms, 2),
    )
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
            pipeline_log(
                logger,
                logging.ERROR,
                stage="ingest",
                status="failed",
                job_id=payload.get("job_id") or payload.get("trace_id"),
                doc_id=rec.get("doc_id"),
                pipeline_stage="embedding",
                error_message=f"Embedding failed: {exc}",
                source_url=payload.get("source_url") or payload.get("page_url"),
                title=payload.get("title"),
            )
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
    *,
    graph: Any | None = None,
    neo4j_database: str | None = None,
) -> tuple[Dict[str, List[tuple[str, str, str]]], List[Dict[str, Any]]]:
    fn_start = time.perf_counter()
    _perf_log(
        "perf:graph pipeline.ingest_logic._build_triplets_by_doc start engine=%s chunk_records=%s",
        engine,
        len(chunk_records),
    )
    if not chunk_records:
        _perf_log("perf:graph pipeline.ingest_logic._build_triplets_by_doc done docs=0 chunks=0 ms=0.00")
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
        logger.info("graph:extract doc_timeout using thread fallback")
    for doc_id, parts in grouped.items():
        doc_total_start = time.perf_counter()
        doc_index += 1
        prep_start = time.perf_counter()
        chunk_texts = [p.get("content") for p in parts if isinstance(p.get("content"), str) and p.get("content").strip()]
        if not chunk_texts:
            out[doc_id] = []
            _perf_log(
                "perf:graph pipeline.ingest_logic._build_triplets_by_doc doc=%s step=prepare empty=true ms=%.2f",
                doc_id,
                (time.perf_counter() - prep_start) * 1000,
            )
            continue
        if GRAPH_DEBUG:
            lens = [len(t) for t in chunk_texts]
            logger.debug(
                "graph:extract doc=%s chunk_lens=%s total_chars=%s",
                doc_id,
                lens[:10],
                sum(lens),
            )
        orig_chunk_count = len(chunk_texts)
        orig_chars = sum(len(t) for t in chunk_texts)
        max_chunks = _int_env("GRAPH_DOC_MAX_CHUNKS", 0)
        max_chars = _int_env("GRAPH_DOC_MAX_CHARS", 0)
        if max_chunks > 0 and len(chunk_texts) > max_chunks:
            chunk_texts = chunk_texts[:max_chunks]
        if max_chars > 0:
            trimmed: List[str] = []
            total = 0
            for text in chunk_texts:
                if total >= max_chars:
                    break
                remaining = max_chars - total
                if len(text) > remaining:
                    trimmed.append(text[:remaining])
                    total = max_chars
                    break
                trimmed.append(text)
                total += len(text)
            chunk_texts = trimmed
        first_payload = (parts[0] or {}).get("payload") if parts else {}
        file_path = None
        if isinstance(first_payload, dict):
            file_path = first_payload.get("file_path") or first_payload.get("page_url") or first_payload.get("source_url")
        total_chars = sum(len(t) for t in chunk_texts)
        if orig_chunk_count != len(chunk_texts) or orig_chars != total_chars:
            logger.info(
                "graph:extract doc=%s trimmed chunks=%s/%s chars=%s/%s",
                doc_id,
                len(chunk_texts),
                orig_chunk_count,
                total_chars,
                orig_chars,
            )
        prep_ms = (time.perf_counter() - prep_start) * 1000
        logger.info(
            "graph:extract doc=%s idx=%s/%s chunks=%s chars=%s file=%s",
            doc_id,
            doc_index,
            total_docs,
            len(chunk_texts),
            total_chars,
            file_path or "-",
        )
        _perf_log(
            "perf:graph pipeline.ingest_logic._build_triplets_by_doc doc=%s step=prepare chunks=%s chars=%s ms=%.2f",
            doc_id,
            len(chunk_texts),
            total_chars,
            prep_ms,
        )
        extract_start = time.perf_counter()
        def _extract():
            return rag_service.extract_triplets(
                "",
                engine,
                provider=provider,
                chunks=chunk_texts,
                doc_id=doc_id,
                file_path=file_path,
                neo4j_database=neo4j_database,
            )
        triplets, error = _run_graph_extract_with_timeout(_extract, doc_timeout_s, allow_alarm=use_alarm)
        extract_ms = (time.perf_counter() - extract_start) * 1000
        if error:
            failures.append({
                "doc_id": str(doc_id),
                "file_path": file_path or "",
                "chunks": len(chunk_texts),
                "chars": total_chars,
                "error": error,
                "timestamp": utc_now_iso(),
            })
            logger.warning("graph:extract doc=%s failed=%s ms=%.2f", doc_id, error, extract_ms)
            _perf_log(
                "perf:graph pipeline.ingest_logic._build_triplets_by_doc doc=%s step=extract status=error ms=%.2f",
                doc_id,
                extract_ms,
            )
            triplets = []
        else:
            _perf_log(
                "perf:graph pipeline.ingest_logic._build_triplets_by_doc doc=%s step=extract raw_triplets=%s ms=%.2f",
                doc_id,
                len(triplets),
                extract_ms,
            )
            clean_start = time.perf_counter()
            triplets = clean_triplets(triplets)
            clean_ms = (time.perf_counter() - clean_start) * 1000
            _perf_log(
                "perf:graph pipeline.ingest_logic._build_triplets_by_doc doc=%s step=clean kept_triplets=%s ms=%.2f",
                doc_id,
                len(triplets),
                clean_ms,
            )
            logger.info("graph:extract doc=%s triplets=%s ms=%.2f", doc_id, len(triplets), extract_ms)
            if graph is not None and triplets:
                neo4j_start = time.perf_counter()
                graph.upsert_triplets(triplets, doc_id=doc_id)
                neo4j_ms = (time.perf_counter() - neo4j_start) * 1000
                _perf_log(
                    "perf:graph pipeline.ingest_logic._build_triplets_by_doc doc=%s step=neo4j_upsert triplets=%s ms=%.2f",
                    doc_id,
                    len(triplets),
                    neo4j_ms,
                )
                logger.info("graph:neo4j upsert doc=%s triplets=%s", doc_id, len(triplets))
        _perf_log(
            "perf:graph pipeline.ingest_logic._build_triplets_by_doc doc=%s step=total ms=%.2f",
            doc_id,
            (time.perf_counter() - doc_total_start) * 1000,
        )
        out[doc_id] = triplets
    _perf_log(
        "perf:graph pipeline.ingest_logic._build_triplets_by_doc done docs=%s chunks=%s total_triplets=%s failures=%s ms=%.2f",
        len(grouped),
        len(chunk_records),
        sum(len(v) for v in out.values()),
        len(failures),
        (time.perf_counter() - fn_start) * 1000,
    )
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
