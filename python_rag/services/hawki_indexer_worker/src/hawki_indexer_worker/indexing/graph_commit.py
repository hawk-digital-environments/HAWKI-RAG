"""Graph extraction and Neo4j commit phase for ingestion."""

from __future__ import annotations

import inspect
import logging
import time
from collections.abc import Callable
from dataclasses import dataclass
from pathlib import Path
from typing import Any

from hawki_indexer_worker.indexing.graph_prepare import (
    append_graph_failures,
    build_triplets_by_doc,
    graph_failure_log_path,
)
from hawki_indexer_worker.indexing.graph_settings import GraphIngestSettings
from hawki_indexer_worker.indexing.summary import build_graph_preview
from hawki_indexer_worker.indexing.observability import pipeline_log
from hawki_rag_stores.neo4j.requests import normalize_graph_write_scope


@dataclass(slots=True)
class GraphCommitResult:
    """Result of extracting graph triplets and writing them to Neo4j."""

    graph_preview: dict[str, Any] | None
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
    graph_factory: Callable[..., Any],
    public_dir: Path,
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
    write_scope = normalize_graph_write_scope(
        requested_dataset_id,
        requested_namespace,
    )
    if write_scope is None:
        logger_obj.warning(
            "graph:canonical write disabled; dataset_id and neo4j_namespace are required dataset_id=%s namespace=%s",
            requested_dataset_id or "-",
            requested_namespace or "-",
        )
    dataset_id = write_scope[0] if write_scope else None
    neo4j_namespace = write_scope[1] if write_scope else None
    if write_scope is not None:
        validate_and_stamp_chunk_scope(
            chunk_records,
            dataset_id=write_scope[0],
            neo4j_namespace=write_scope[1],
        )
    graph = _create_graph(
        graph_factory,
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


def _create_graph(
    graph_factory: Callable[..., Any],
    *,
    database: str | None,
    dataset_id: str | None,
    neo4j_namespace: str | None,
) -> Any:
    """Pass logical scope where supported without breaking legacy test factories."""

    try:
        parameters = inspect.signature(graph_factory).parameters.values()
    except (TypeError, ValueError):
        parameters = ()
    parameter_by_name = {parameter.name: parameter for parameter in parameters}
    names = set(parameter_by_name)
    accepts_kwargs = any(
        parameter.kind is inspect.Parameter.VAR_KEYWORD for parameter in parameters
    )
    kwargs: dict[str, str | None] = {}
    if accepts_kwargs or "dataset_id" in names:
        kwargs["dataset_id"] = dataset_id
    if accepts_kwargs or "neo4j_namespace" in names:
        kwargs["neo4j_namespace"] = neo4j_namespace
    database_parameter = parameter_by_name.get("database")
    if accepts_kwargs or (
        database_parameter is not None
        and database_parameter.kind is not inspect.Parameter.POSITIONAL_ONLY
    ):
        kwargs["database"] = database
        return graph_factory(**kwargs)
    if database is not None:
        return graph_factory(database, **kwargs)
    return graph_factory(**kwargs)


def _record_graph_failures(
    *,
    public_dir: Path,
    failures: list[dict[str, Any]],
    job_id: str | None,
    operation_id: str | None,
    graph_settings: GraphIngestSettings,
    logger_obj: logging.Logger,
) -> None:
    failure_path = graph_failure_log_path(
        public_dir, failure_log_path=graph_settings.graph_failure_log
    )
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


__all__ = [
    "GraphCommitResult",
    "GraphScopeMismatchError",
    "commit_graph_triplets",
    "validate_and_stamp_chunk_scope",
]
