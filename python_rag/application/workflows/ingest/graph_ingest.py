from __future__ import annotations

import json
import logging
import signal
import threading
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from infrastructure.graph.graph_utils import filter_triplets_to_source
from infrastructure.graph.graph_visualization import write_graph_visualization
from application.workflows.ingest.settings import GraphIngestSettings, load_graph_ingest_settings

logger = logging.getLogger(__name__)


class GraphTimeout(Exception):
    pass


def perf_log(
    msg: str,
    *args: Any,
    graph_perf_log: bool | None = None,
    settings: GraphIngestSettings | None = None,
) -> None:
    if graph_perf_log is None:
        graph_perf_log = (settings or load_graph_ingest_settings()).graph_perf_log
    if graph_perf_log:
        logger.info(msg, *args)


def utc_now_iso() -> str:
    return datetime.now(tz=timezone.utc).isoformat()


def graph_failure_log_path(public_dir: Path, *, failure_log_path: str | Path | None = None) -> Path:
    settings = load_graph_ingest_settings()
    env_path = str(failure_log_path).strip() if failure_log_path is not None else settings.graph_failure_log
    if env_path:
        return Path(env_path)
    return public_dir.parent / "storage" / "logs" / "ingest_graph_failures.jsonl"


def append_graph_failures(path: Path, failures: list[dict[str, Any]]) -> None:
    if not failures:
        return
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        with path.open("a", encoding="utf-8") as handle:
            for item in failures:
                handle.write(json.dumps(item, ensure_ascii=False) + "\n")
    except Exception as exc:
        logger.warning("graph:failed to write failures log: %s", exc)


def run_graph_extract_with_timeout(
    func,
    timeout_s: float,
    *,
    allow_alarm: bool,
) -> tuple[list[tuple[str, str, str]], str | None]:
    if timeout_s <= 0:
        try:
            return func(), None
        except Exception as exc:
            return [], f"{type(exc).__name__}: {exc}"

    if allow_alarm:
        def _alarm_handler(signum, frame):
            raise GraphTimeout("graph extraction timed out")
        previous_handler = signal.signal(signal.SIGALRM, _alarm_handler)
        signal.setitimer(signal.ITIMER_REAL, timeout_s)
        try:
            return func(), None
        except GraphTimeout as exc:
            return [], str(exc)
        except Exception as exc:
            return [], f"{type(exc).__name__}: {exc}"
        finally:
            signal.setitimer(signal.ITIMER_REAL, 0)
            signal.signal(signal.SIGALRM, previous_handler)

    result: dict[str, Any] = {"done": False, "value": [], "error": None}

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


def build_triplets_by_doc(
    chunk_records: list[dict[str, Any]],
    engine: str,
    rag_service: Any,
    provider: Any | None,
    *,
    graph: Any | None = None,
    neo4j_database: str | None = None,
    public_dir: Path | None = None,
    graph_debug: bool | None = None,
    graph_perf_log: bool | None = None,
    graph_doc_timeout_s: float | None = None,
    graph_doc_max_chunks: int | None = None,
    graph_doc_max_chars: int | None = None,
    failure_log_path: str | Path | None = None,
    graph_settings: GraphIngestSettings | None = None,
    request_id: str | None = None,
) -> tuple[dict[str, list[tuple[str, str, str]]], list[dict[str, Any]]]:
    resolved_settings = graph_settings or load_graph_ingest_settings()
    if graph_debug is None:
        graph_debug = resolved_settings.graph_debug
    if graph_perf_log is None:
        graph_perf_log = resolved_settings.graph_perf_log
    if graph_doc_timeout_s is None:
        graph_doc_timeout_s = resolved_settings.graph_doc_timeout_s
    if graph_doc_max_chars is None:
        graph_doc_max_chars = resolved_settings.graph_doc_max_chars
    if graph_doc_max_chunks is None:
        graph_doc_max_chunks = resolved_settings.graph_doc_max_chunks
    fn_start = time.perf_counter()
    perf_log(
        "perf:graph pipeline.ingest_logic._build_triplets_by_doc start engine=%s chunk_records=%s",
        engine,
        len(chunk_records),
        graph_perf_log=graph_perf_log,
        settings=resolved_settings,
    )
    if not chunk_records:
        perf_log(
            "perf:graph pipeline.ingest_logic._build_triplets_by_doc done docs=0 chunks=0 ms=0.00",
            graph_perf_log=graph_perf_log,
            settings=resolved_settings,
        )
        return {}, []
    grouped: dict[str, list[dict[str, Any]]] = {}
    for rec in chunk_records:
        grouped.setdefault(rec["doc_id"], []).append(rec)
    provider_name = provider.__class__.__name__ if provider is not None else "none"
    rag_model = getattr(provider, "rag_model", None)
    embed_model = getattr(provider, "embed_model", None)
    if graph_doc_timeout_s > 0:
        logger.info("graph:extract doc_timeout=%.2fs", graph_doc_timeout_s)
    logger.info(
        "graph:extract start engine=%s docs=%s chunks=%s provider=%s rag_model=%s embed_model=%s",
        engine,
        len(grouped),
        len(chunk_records),
        provider_name,
        rag_model,
        embed_model,
    )
    out: dict[str, list[tuple[str, str, str]]] = {}
    failures: list[dict[str, Any]] = []
    total_docs = len(grouped)
    doc_index = 0
    use_alarm = graph_doc_timeout_s > 0 and threading.current_thread() is threading.main_thread()
    if graph_doc_timeout_s > 0 and not use_alarm:
        logger.info("graph:extract doc_timeout using thread fallback")
    for doc_id, parts in grouped.items():
        doc_total_start = time.perf_counter()
        doc_index += 1
        prep_start = time.perf_counter()
        chunk_texts = [p.get("content") for p in parts if isinstance(p.get("content"), str) and p.get("content").strip()]
        if not chunk_texts:
            out[doc_id] = []
            perf_log(
                "perf:graph pipeline.ingest_logic._build_triplets_by_doc doc=%s step=prepare empty=true ms=%.2f",
                doc_id,
                (time.perf_counter() - prep_start) * 1000,
                graph_perf_log=graph_perf_log,
                settings=resolved_settings,
            )
            continue
        if graph_debug:
            lens = [len(t) for t in chunk_texts]
            logger.debug(
                "graph:extract doc=%s chunk_lens=%s total_chars=%s",
                doc_id,
                lens[:10],
                sum(lens),
            )
        orig_chunk_count = len(chunk_texts)
        orig_chars = sum(len(t) for t in chunk_texts)
        max_chunks = graph_doc_max_chunks
        max_chars = graph_doc_max_chars
        if max_chunks > 0 and len(chunk_texts) > max_chunks:
            chunk_texts = chunk_texts[:max_chunks]
        if max_chars > 0:
            trimmed: list[str] = []
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
        perf_log(
            "perf:graph pipeline.ingest_logic._build_triplets_by_doc doc=%s step=prepare chunks=%s chars=%s ms=%.2f",
            doc_id,
            len(chunk_texts),
            total_chars,
            prep_ms,
            graph_perf_log=graph_perf_log,
            settings=resolved_settings,
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
        triplets, error = run_graph_extract_with_timeout(_extract, graph_doc_timeout_s, allow_alarm=use_alarm)
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
            perf_log(
                "perf:graph pipeline.ingest_logic._build_triplets_by_doc doc=%s step=extract status=error ms=%.2f",
                doc_id,
                extract_ms,
                graph_perf_log=graph_perf_log,
                settings=resolved_settings,
            )
            triplets = []
        else:
            perf_log(
                "perf:graph pipeline.ingest_logic._build_triplets_by_doc doc=%s step=extract raw_triplets=%s ms=%.2f",
                doc_id,
                len(triplets),
                extract_ms,
                graph_perf_log=graph_perf_log,
                settings=resolved_settings,
            )
            clean_start = time.perf_counter()
            triplets = filter_triplets_to_source(
                triplets,
                "\n\n".join(chunk_texts),
                graph_perf_log=graph_perf_log,
            )
            clean_ms = (time.perf_counter() - clean_start) * 1000
            perf_log(
                "perf:graph pipeline.ingest_logic._build_triplets_by_doc doc=%s step=clean kept_triplets=%s ms=%.2f",
                doc_id,
                len(triplets),
                clean_ms,
                graph_perf_log=graph_perf_log,
                settings=resolved_settings,
            )
            logger.info("graph:extract doc=%s triplets=%s ms=%.2f", doc_id, len(triplets), extract_ms)
            if graph is not None and triplets:
                neo4j_start = time.perf_counter()
                graph.upsert_triplets(triplets, doc_id=doc_id, request_id=request_id)
                neo4j_ms = (time.perf_counter() - neo4j_start) * 1000
                if public_dir is not None:
                    try:
                        write_graph_visualization(public_dir, database=neo4j_database, recent_doc_id=doc_id)
                    except Exception as exc:
                        logger.warning("graph-viz:update failed doc=%s: %s", doc_id, exc)
                perf_log(
                    "perf:graph pipeline.ingest_logic._build_triplets_by_doc doc=%s step=neo4j_upsert triplets=%s ms=%.2f",
                    doc_id,
                    len(triplets),
                    neo4j_ms,
                    graph_perf_log=graph_perf_log,
                    settings=resolved_settings,
                )
                logger.info("graph:neo4j upsert doc=%s triplets=%s", doc_id, len(triplets))
            perf_log(
                "perf:graph pipeline.ingest_logic._build_triplets_by_doc doc=%s step=total ms=%.2f",
                doc_id,
                (time.perf_counter() - doc_total_start) * 1000,
                graph_perf_log=graph_perf_log,
                settings=resolved_settings,
            )
        out[doc_id] = triplets
    perf_log(
        "perf:graph pipeline.ingest_logic._build_triplets_by_doc done docs=%s chunks=%s total_triplets=%s failures=%s ms=%.2f",
        len(grouped),
        len(chunk_records),
        sum(len(v) for v in out.values()),
        len(failures),
        (time.perf_counter() - fn_start) * 1000,
        graph_perf_log=graph_perf_log,
        settings=resolved_settings,
    )
    return out, failures
