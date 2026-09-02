"""Contract validation and dependency-boundary tests."""

from __future__ import annotations

import hashlib
from pathlib import Path
import tomllib

import pytest
from pydantic import ValidationError

from hawki_rag_contracts.pipeline.artifacts import MarkdownArtifact
from hawki_rag_contracts.retrieval.auth_scope import AuthorizedQueryScope
from hawki_rag_contracts.pipeline.ingestion import (
    IngestSourceWorkflowInput,
    TaskQueueConfig,
    shared_storage_root,
)
from hawki_rag_contracts.pipeline.identity import document_id, sha256_text
from hawki_rag_contracts.retrieval.query import QueryRequest
from hawki_rag_contracts.retrieval.rerank import RerankRequest, RerankResponse
from hawki_rag_contracts.pipeline.status import PipelineWorkerEvent
from hawki_rag_contracts.pipeline.temporal import (
    ActivityQueueRole,
    CONVERT_FILES_ACTIVITY,
    INDEX_MARKDOWN_ACTIVITY,
    INGEST_SOURCE_WORKFLOW,
    LEGACY_INGESTION_TASK_QUEUE,
    MARK_SOURCE_READY_ACTIVITY,
    SCRAPE_SOURCE_ACTIVITY,
    resolve_activity_task_queue,
    resolve_legacy_ingestion_task_queue,
)


PYTHON_RAG = next(
    parent
    for parent in Path(__file__).resolve().parents
    if (parent / "uv.lock").is_file()
)
PACKAGE_ROOT = PYTHON_RAG / "packages" / "contracts"


def _authorized_scope(**updates: object) -> AuthorizedQueryScope:
    payload: dict[str, object] = {
        "dataset_id": "dataset-a",
        "qdrant_collection": "hawki_dataset_a",
        "neo4j_namespace": "graph_dataset_a",
        "embedding_provider": "OLLAMA",
        "embedding_model": "bge-m3",
        "graph_enabled": True,
    }
    payload.update(updates)
    return AuthorizedQueryScope.model_validate(payload)


def test_temporal_names_and_indexer_queue_fallback_are_stable() -> None:
    assert INGEST_SOURCE_WORKFLOW == "IngestSourceWorkflow"
    assert SCRAPE_SOURCE_ACTIVITY == "scrape_source"
    assert CONVERT_FILES_ACTIVITY == "inspect_and_convert_files"
    assert INDEX_MARKDOWN_ACTIVITY == "ingest_markdown_files"
    assert MARK_SOURCE_READY_ACTIVITY == "mark_source_ready"

    assert (
        resolve_activity_task_queue(
            {"task_queues": {"indexer": "new-indexer", "ingestion": "legacy-indexer"}},
            ActivityQueueRole.INDEXER,
        )
        == "new-indexer"
    )
    assert (
        resolve_activity_task_queue(
            {"task_queues": {"ingestion": "legacy-indexer"}},
            ActivityQueueRole.INDEXER,
        )
        == "legacy-indexer"
    )
    assert (
        resolve_activity_task_queue({}, ActivityQueueRole.INDEXER)
        == LEGACY_INGESTION_TASK_QUEUE
    )
    assert (
        TaskQueueConfig(ingestion="legacy-indexer").resolved_indexer == "legacy-indexer"
    )

    legacy_value = "  legacy-indexer-with-whitespace  "
    assert (
        resolve_legacy_ingestion_task_queue(
            {"task_queues": {"ingestion": legacy_value}}
        )
        == legacy_value
    )
    assert (
        resolve_activity_task_queue(
            {"task_queues": {"scraper": legacy_value}},
            ActivityQueueRole.SCRAPER,
        )
        == legacy_value
    )


def test_authorized_scope_preserves_the_laravel_owned_query_boundary() -> None:
    scope = _authorized_scope()

    assert scope.embedding_provider == "ollama"
    assert scope.graph_enabled is True

    with pytest.raises(ValidationError, match="neo4j_namespace"):
        _authorized_scope(neo4j_namespace=None)

    with pytest.raises(ValidationError, match="extra_forbidden"):
        _authorized_scope(auth_context={"user": "caller"})


def test_query_provider_must_match_the_authorized_vector_space() -> None:
    request = QueryRequest(
        query="How does Temporal route activities?",
        authorized_scope=_authorized_scope(),
        provider="OLLAMA",
        chat_model="llama3.1:8b",
        vision_model="qwen2.5vl:7b",
    )
    assert request.provider == "ollama"

    with pytest.raises(ValidationError, match="provider must match"):
        QueryRequest(
            query="How does Temporal route activities?",
            authorized_scope=_authorized_scope(),
            provider="litellm",
            chat_model="llama3.1:8b",
            vision_model="qwen2.5vl:7b",
        )


@pytest.mark.parametrize("legacy_field", ["embedding_model", "graph_model"])
def test_query_rejects_legacy_root_model_fields(legacy_field: str) -> None:
    payload: dict[str, object] = {
        "query": "How does Temporal route activities?",
        "authorized_scope": _authorized_scope(),
        "provider": "ollama",
        "chat_model": "llama3.1:8b",
        "vision_model": "qwen2.5vl:7b",
        legacy_field: "some-model",
    }

    with pytest.raises(ValidationError) as exc_info:
        QueryRequest.model_validate(payload)

    assert any(
        error["type"] == "extra_forbidden" and error["loc"] == (legacy_field,)
        for error in exc_info.value.errors()
    )


def test_artifact_and_reranker_contracts_reject_malformed_wire_data() -> None:
    artifact = MarkdownArtifact(
        uri="file:///shared/sources/source-a/markdown/page.md",
        source_id="source-a",
        document_id="doc-a",
        content_hash="a" * 64,
        sha256="b" * 64,
    )
    assert artifact.kind == "markdown"

    with pytest.raises(ValidationError, match="string_pattern_mismatch"):
        artifact.model_copy(update={"content_hash": "not-a-sha"}).model_validate(
            {**artifact.model_dump(), "content_hash": "not-a-sha"}
        )

    request = RerankRequest(query="Temporal", documents=["Worker routing"])
    response = RerankResponse.model_validate(
        {
            "results": [
                {"index": 0, "document": request.documents[0], "relevance_score": 0.75}
            ]
        }
    )
    assert response.results[0].index == 0


def test_laravel_workflow_payload_accepts_flat_services_and_optional_collection() -> (
    None
):
    workflow_input = IngestSourceWorkflowInput.model_validate(
        {
            "source_id": "source-a",
            "source_url": "https://example.test/source",
            "dataset_id": "dataset-a",
            "task_id": "task-a",
            "job_id": "job-a",
            "raw_output_path": "/shared/sources/source-a/raw",
            "markdown_output_path": "/shared/sources/source-a/markdown",
            "storage": {"mode": "shared", "shared_root": "/shared"},
            "ingestion": {
                "provider": "ollama",
                "embedding_model": "bge-m3",
                "graph_model": "llama3.1:8b",
                "vision_model": "qwen2.5vl:7b",
                "collection": None,
            },
            "external_services": {
                "scraper_url": "http://crawl4ai-service",
                "scraper_token": "secret-reference-value",
            },
        }
    )

    assert workflow_input.ingestion.collection is None
    assert workflow_input.external_services["scraper_url"] == (
        "http://crawl4ai-service"
    )


def test_shared_storage_root_accepts_current_and_legacy_shared_payloads() -> None:
    assert shared_storage_root({"storage": {"shared_root": "/shared"}}) == "/shared"
    assert (
        shared_storage_root(
            {
                "storage": {
                    "mode": "shared",
                    "shared_root": "/shared",
                    "object_prefix": "s3://retired-prefix",
                }
            }
        )
        == "/shared"
    )


@pytest.mark.parametrize(
    "workflow_input",
    [
        {},
        {"storage": {}},
        {"storage": {"mode": "object", "object_prefix": "s3://bucket/prefix"}},
    ],
)
def test_shared_storage_root_rejects_missing_or_object_storage(
    workflow_input: dict[str, object],
) -> None:
    with pytest.raises(ValueError):
        shared_storage_root(workflow_input)


def test_document_identity_uses_utf8_and_posix_relative_paths() -> None:
    source_id = "source-\u00e4"
    relative_path = Path("nested") / "\u00dcberblick.md"
    text = "Gr\u00fc\u00dfe \u2615\n"

    expected_hash = hashlib.sha256(text.encode("utf-8")).hexdigest()
    expected_document_hash = hashlib.sha256(
        f"{source_id}|nested/\u00dcberblick.md".encode("utf-8")
    ).hexdigest()[:40]

    assert sha256_text(text) == expected_hash
    assert document_id(source_id, relative_path) == f"doc_{expected_document_hash}"


@pytest.mark.parametrize("relative_path", ["/absolute.md", "../escape.md", ""])
def test_document_identity_rejects_paths_outside_the_artifact_directory(
    relative_path: str,
) -> None:
    with pytest.raises(ValueError, match="relative_path"):
        document_id("source-a", relative_path)


def test_document_identity_rejects_empty_source_id() -> None:
    with pytest.raises(ValueError, match="source_id"):
        document_id("", "page.md")


def test_worker_event_accepts_timestamp_alias_and_enforces_stage_ownership() -> None:
    payload = {
        "schema_version": 1,
        "event_id": "event-a",
        "event_type": "pipeline.stage.status",
        "producer": "indexer",
        "timestamp": "2026-08-03T12:00:00Z",
        "workflow_id": "ingest-source-source-a",
        "run_id": "run-a",
        "activity_id": "ingest_markdown_files",
        "attempt": 1,
        "job_id": "job-a",
        "task_id": "task-a",
        "source_id": "source-a",
        "stage": "ingest",
        "phase": "index",
        "status": "completed",
        "counts": {"total": 2, "processed": 2, "failed": 0, "skipped": 0},
        "artifacts": [],
        "manifest": None,
        "errors": [],
        "warnings": [],
    }
    event = PipelineWorkerEvent.model_validate(payload)

    assert event.occurred_at.isoformat() == "2026-08-03T12:00:00+00:00"
    wire_event = event.model_dump(mode="json", by_alias=True)
    assert wire_event["timestamp"] == "2026-08-03T12:00:00Z"
    assert "occurred_at" not in wire_event

    with pytest.raises(ValidationError, match="producer is not allowed"):
        PipelineWorkerEvent.model_validate({**payload, "producer": "scraper"})


def test_monitor_artifacts_are_limited_to_terminal_indexer_ready_events() -> None:
    payload = {
        "schema_version": 1,
        "event_id": "event-monitor-a",
        "event_type": "pipeline.stage.status",
        "producer": "indexer",
        "timestamp": "2026-08-03T12:00:00Z",
        "workflow_id": "ingest-source-source-a",
        "run_id": "run-a",
        "activity_id": MARK_SOURCE_READY_ACTIVITY,
        "attempt": 1,
        "job_id": "job-a",
        "task_id": "task-a",
        "source_id": "source-a",
        "stage": "ingest",
        "phase": MARK_SOURCE_READY_ACTIVITY,
        "status": "completed",
        "monitor_artifacts": {
            "summary": {"documents": {"processed_docs": 1}},
            "graph_preview": {"total_docs": 1},
            "graph_failures": [
                {
                    "doc_id": "doc-a",
                    "chunks": 1,
                    "chars": 100,
                    "error": "Graph extraction timed out.",
                    "timestamp": "2026-08-03T11:59:59Z",
                }
            ],
        },
    }

    event = PipelineWorkerEvent.model_validate(payload)
    assert event.monitor_artifacts is not None
    assert event.monitor_artifacts.graph_failures[0].doc_id == "doc-a"

    with pytest.raises(ValidationError, match="terminal mark_source_ready"):
        PipelineWorkerEvent.model_validate(
            {**payload, "activity_id": INDEX_MARKDOWN_ACTIVITY}
        )


def test_contract_package_is_exactly_pinned_and_has_no_io_or_service_imports() -> None:
    pyproject = tomllib.loads(
        (PACKAGE_ROOT / "pyproject.toml").read_text(encoding="utf-8")
    )
    assert pyproject["project"]["requires-python"] == "==3.13.14"
    assert pyproject["project"]["dependencies"] == ["pydantic==2.13.4"]
    assert pyproject["build-system"]["requires"] == ["uv_build==0.11.26"]

    forbidden = (
        "fastapi",
        "psycopg",
        "requests",
        "sqlalchemy",
        "temporalio",
        "hawki_bridge",
        "hawki_workflow_worker",
        "hawki_scraper_worker",
        "hawki_converter_worker",
        "hawki_indexer_worker",
        "hawki_reranker",
    )
    source_root = PACKAGE_ROOT / "src" / "hawki_rag_contracts"
    for source in source_root.rglob("*.py"):
        contents = source.read_text(encoding="utf-8")
        assert not any(name in contents for name in forbidden), source
        assert len(contents.splitlines()) < 600, source


def test_contract_domains_do_not_import_each_other() -> None:
    source_root = PACKAGE_ROOT / "src" / "hawki_rag_contracts"
    pipeline_sources = "\n".join(
        path.read_text(encoding="utf-8")
        for path in (source_root / "pipeline").glob("*.py")
    )
    retrieval_sources = "\n".join(
        path.read_text(encoding="utf-8")
        for path in (source_root / "retrieval").glob("*.py")
    )

    assert "hawki_rag_contracts.retrieval" not in pipeline_sources
    assert "hawki_rag_contracts.pipeline" not in retrieval_sources


def test_legacy_contract_modules_are_identity_preserving_aliases() -> None:
    from hawki_rag_contracts.artifacts import MarkdownArtifact as LegacyArtifact
    from hawki_rag_contracts.auth_scope import (
        AuthorizedQueryScope as LegacyAuthorizedQueryScope,
    )
    from hawki_rag_contracts.ingestion import (
        IngestSourceWorkflowInput as LegacyWorkflowInput,
    )
    from hawki_rag_contracts.query import QueryRequest as LegacyQueryRequest
    from hawki_rag_contracts.rerank import RerankRequest as LegacyRerankRequest
    from hawki_rag_contracts.status import PipelineWorkerEvent as LegacyWorkerEvent
    from hawki_rag_contracts.temporal import (
        ActivityQueueRole as LegacyActivityQueueRole,
    )

    assert LegacyArtifact is MarkdownArtifact
    assert LegacyAuthorizedQueryScope is AuthorizedQueryScope
    assert LegacyWorkflowInput is IngestSourceWorkflowInput
    assert LegacyQueryRequest is QueryRequest
    assert LegacyRerankRequest is RerankRequest
    assert LegacyWorkerEvent is PipelineWorkerEvent
    assert LegacyActivityQueueRole is ActivityQueueRole
