"""Graph extraction and Neo4j commit phase for ingestion."""

from __future__ import annotations

import logging
import time
from dataclasses import dataclass
from typing import Any

from hawki_indexer_worker.domain.graph import resolve_indexing_graph_scope
from hawki_indexer_worker.domain.ports import GraphWriterFactory
from hawki_indexer_worker.indexing.graph_prepare import build_triplets_by_doc
from hawki_indexer_worker.indexing.graph_cleanup import close_graph_safely
from hawki_indexer_worker.indexing.graph_settings import GraphIngestSettings
from hawki_indexer_worker.indexing.summary import build_graph_preview
from hawki_indexer_worker.indexing.observability import pipeline_log


@dataclass(slots=True)
class GraphCommitResult:
    """Result of extracting graph triplets and writing them to Neo4j."""

    graph_preview: dict[str, Any] | None
    graph_failures: list[dict[str, Any]]
    neo4j_ms: float | None


class GraphScopeMismatchError(ValueError):
    """A document payload conflicts with the trusted graph write scope."""


def commit_graph_triplets(
    *,
    body: Any,
    chunk_records: list[dict[str, Any]],
    doc_stats: dict[str, Any],
    rag_service: Any,
    provider: Any | None,
    graph_factory: GraphWriterFactory,
    job_id: str | None,
    operation_id: str | None,
    graph_debug: bool,
    graph_settings: GraphIngestSettings,
    logger_obj: logging.Logger,
    replace_doc_ids_by_doc: dict[str, set[str]] | None = None,
) -> GraphCommitResult:
    """Extract graph triplets, upsert them to Neo4j, and build graph preview data."""

    neo4j_database = _optional_string(getattr(body, "neo4j_database", None))
    requested_dataset_id = _optional_string(getattr(body, "dataset_id", None))
    requested_namespace = _optional_string(getattr(body, "neo4j_namespace", None))
    write_scope = resolve_indexing_graph_scope(
        requested_dataset_id,
        requested_namespace,
    )
    if write_scope is None:
        logger_obj.warning(
            "graph:canonical write disabled; dataset_id and neo4j_namespace are required dataset_id=%s namespace=%s",
            requested_dataset_id or "-",
            requested_namespace or "-",
        )
    dataset_id = write_scope.dataset_id if write_scope else None
    neo4j_namespace = write_scope.neo4j_namespace if write_scope else None
    if write_scope is not None:
        validate_and_stamp_chunk_scope(
            chunk_records,
            dataset_id=write_scope.dataset_id,
            neo4j_namespace=write_scope.neo4j_namespace,
        )
    graph = graph_factory(
        database=neo4j_database,
        dataset_id=dataset_id,
        neo4j_namespace=neo4j_namespace,
    )
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
            dataset_id=dataset_id,
            neo4j_namespace=neo4j_namespace,
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
                failures=failures,
                job_id=job_id,
                operation_id=operation_id,
                logger_obj=logger_obj,
            )
        return GraphCommitResult(
            graph_preview=graph_preview,
            graph_failures=failures,
            neo4j_ms=neo4j_ms,
        )
    finally:
        close_graph_safely(graph, logger_obj=logger_obj, operation="commit_triplets")


def _optional_string(value: object) -> str | None:
    normalized = str(value or "").strip()
    return normalized or None


def validate_and_stamp_chunk_scope(
    chunk_records: list[dict[str, Any]],
    *,
    dataset_id: str,
    neo4j_namespace: str,
) -> None:
    """Validate existing payload scope, then stamp the trusted request scope."""

    for record in chunk_records:
        payload = record.get("payload")
        if not isinstance(payload, dict):
            payload = {}
            record["payload"] = payload
        for key, trusted_value in (
            ("dataset_id", dataset_id),
            ("neo4j_namespace", neo4j_namespace),
        ):
            existing_value = _optional_string(payload.get(key))
            if existing_value is not None and existing_value != trusted_value:
                raise GraphScopeMismatchError(
                    f"Chunk {record.get('doc_id') or '-'} has {key}={existing_value!r}, "
                    f"which conflicts with trusted {key}={trusted_value!r}."
                )
        payload["dataset_id"] = dataset_id
        payload["neo4j_namespace"] = neo4j_namespace


def _record_graph_failures(
    *,
    failures: list[dict[str, Any]],
    job_id: str | None,
    operation_id: str | None,
    logger_obj: logging.Logger,
) -> None:
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
    logger_obj.info("graph:failures count=%s submitted_via=worker_event", len(failures))


__all__ = [
    "GraphCommitResult",
    "GraphScopeMismatchError",
    "commit_graph_triplets",
    "validate_and_stamp_chunk_scope",
]
