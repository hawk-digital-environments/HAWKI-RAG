"""Graph extraction and Neo4j commit phase for ingestion."""

from __future__ import annotations

import logging
import time
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from application.workflows.ingest.graph_ingest import (
    append_graph_failures,
    build_triplets_by_doc,
    graph_failure_log_path,
)
from application.workflows.ingest.settings import GraphIngestSettings
from application.workflows.ingest.summary import build_graph_preview
from application.workflows.observability import pipeline_log


@dataclass(slots=True)
class GraphCommitResult:
    """Result of extracting graph triplets and writing them to Neo4j."""

    graph_preview: dict[str, Any] | None
    neo4j_ms: float | None


def commit_graph_triplets(
    *,
    body: Any,
    chunk_records: list[dict[str, Any]],
    doc_stats: dict[str, Any],
    rag_service: Any,
    provider: Any | None,
    graph_factory: Callable[[str | None], Any],
    public_dir: Path,
    job_id: str | None,
    operation_id: str | None,
    graph_debug: bool,
    graph_settings: GraphIngestSettings,
    logger_obj: logging.Logger,
    replace_doc_ids_by_doc: dict[str, set[str]] | None = None,
) -> GraphCommitResult:
    """Extract graph triplets, upsert them to Neo4j, and build graph preview data."""

    neo4j_database = getattr(body, "neo4j_database", None)
    graph = graph_factory(neo4j_database)
    try:
        graph_write_start = time.perf_counter()
        triplet_start = time.perf_counter()
        triplets_by_doc, failures = build_triplets_by_doc(
            chunk_records,
            body.graph_engine,
            rag_service,
            provider,
            graph_debug=graph_debug,
            graph=graph,
            neo4j_database=neo4j_database,
            public_dir=public_dir,
            request_id=operation_id,
            replace_doc_ids_by_doc=replace_doc_ids_by_doc,
            graph_settings=graph_settings,
        )
        triplet_ms = (time.perf_counter() - triplet_start) * 1000
        total_triplets = sum(len(v) for v in triplets_by_doc.values())
        logger_obj.info(
            "graph:extract done request_id=%s engine=%s triplets=%s docs=%s ms=%.2f",
            operation_id or "-",
            body.graph_engine,
            total_triplets,
            len(triplets_by_doc),
            triplet_ms,
        )
        graph_preview = build_graph_preview(doc_stats, chunk_records, triplets_by_doc)
        neo4j_ms = (time.perf_counter() - graph_write_start) * 1000
        logger_obj.info(
            "graph:neo4j upsert request_id=%s docs=%s triplets=%s ms=%.2f",
            operation_id or "-",
            len(triplets_by_doc),
            total_triplets,
            neo4j_ms,
        )
        if failures:
            _record_graph_failures(
                public_dir=public_dir,
                failures=failures,
                job_id=job_id,
                operation_id=operation_id,
                graph_settings=graph_settings,
                logger_obj=logger_obj,
            )
        return GraphCommitResult(graph_preview=graph_preview, neo4j_ms=neo4j_ms)
    finally:
        try:
            graph.close()
        except Exception:
            pass


def _record_graph_failures(
    *,
    public_dir: Path,
    failures: list[dict[str, Any]],
    job_id: str | None,
    operation_id: str | None,
    graph_settings: GraphIngestSettings,
    logger_obj: logging.Logger,
) -> None:
    failure_path = graph_failure_log_path(public_dir, failure_log_path=graph_settings.graph_failure_log)
    append_graph_failures(failure_path, failures)
    for failure in failures:
        pipeline_log(
            logger_obj,
            logging.WARNING,
            stage="ingest",
            status="partial",
            job_id=job_id,
            idempotency_key=operation_id,
            doc_id=failure.get("doc_id"),
            pipeline_stage="graph_extract",
            error_message=failure.get("error"),
            file_path=failure.get("file_path"),
        )
    logger_obj.info("graph:failures count=%s file=%s", len(failures), failure_path)


__all__ = ["GraphCommitResult", "commit_graph_triplets"]
