from __future__ import annotations

import hashlib
import json
from pathlib import Path
from typing import Any

import pytest

from hawki_artifact_store.local import LocalArtifactStore
from hawki_rag_contracts.pipeline.artifacts import MarkdownArtifact
from hawki_rag_contracts.pipeline.identity import document_id
from hawki_indexer_worker.indexing.artifact_documents import (
    ArtifactPreparationContext,
    prepare_artifact_batch,
)
from hawki_indexer_worker.indexing.chunking import prepare_documents


def _workflow_input(tmp_path: Path) -> dict[str, Any]:
    return {
        "source_id": "source-1",
        "source_url": "https://example.test/source",
        "dataset_id": "dataset-1",
        "job_id": "job-1",
        "task_id": "task-1",
        "raw_output_path": str(tmp_path / "raw"),
    }


def test_prepare_artifact_batch_builds_document_and_manifest_record(
    tmp_path: Path,
) -> None:
    markdown_dir = tmp_path / "markdown"
    markdown_dir.mkdir()
    markdown_file = markdown_dir / "page.md"
    markdown_file.write_text("# Article\n\nUseful content", encoding="utf-8")
    content = markdown_file.read_bytes()
    content_hash = hashlib.sha256(content).hexdigest()
    artifact = MarkdownArtifact(
        uri=str(markdown_file),
        relative_path="page.md",
        sha256=hashlib.sha256(content).hexdigest(),
        size_bytes=len(content),
        media_type="text/markdown",
        source_id="source-1",
        document_id=document_id("source-1", "page.md"),
        content_hash=content_hash,
    )
    context = ArtifactPreparationContext(
        workflow_input=_workflow_input(tmp_path),
        options={
            "collection": "dataset-1",
            "neo4j_namespace": "hawki_dataset_1",
        },
        markdown_dir=str(markdown_dir),
        artifact_store=LocalArtifactStore(tmp_path),
        artifacts_by_path={str(markdown_file): artifact},
    )

    prepared = prepare_artifact_batch([str(markdown_file)], context)

    assert prepared.skipped_documents == 0
    assert len(prepared.documents) == 1
    document = prepared.documents[0]
    assert document.id == artifact.document_id
    assert document.payload["content_hash"] == content_hash
    assert document.payload["qdrant_collection"] == "dataset-1"
    assert document.payload["neo4j_namespace"] == "hawki_dataset_1"
    assert prepared.manifest_records == [
        {
            "document_id": artifact.document_id,
            "relative_path": "page.md",
            "content_hash": content_hash,
            "markdown_path": str(markdown_file),
        }
    ]


def test_prepare_artifact_batch_rejects_invalid_artifact_size(tmp_path: Path) -> None:
    markdown_dir = tmp_path / "markdown"
    markdown_dir.mkdir()
    markdown_file = markdown_dir / "page.md"
    markdown_file.write_text("# Article", encoding="utf-8")
    content = markdown_file.read_bytes()
    artifact = MarkdownArtifact(
        uri=str(markdown_file),
        relative_path="page.md",
        sha256=hashlib.sha256(content).hexdigest(),
        size_bytes=len(content) + 1,
        media_type="text/markdown",
        source_id="source-1",
        document_id=document_id("source-1", "page.md"),
        content_hash=hashlib.sha256(content).hexdigest(),
    )
    context = ArtifactPreparationContext(
        workflow_input=_workflow_input(tmp_path),
        options={},
        markdown_dir=str(markdown_dir),
        artifact_store=LocalArtifactStore(tmp_path),
        artifacts_by_path={str(markdown_file): artifact},
    )

    with pytest.raises(RuntimeError, match="size_bytes"):
        prepare_artifact_batch([str(markdown_file)], context)


def test_prepare_direct_text_artifact_without_a_raw_directory(tmp_path: Path) -> None:
    markdown_dir = tmp_path / "markdown"
    markdown_dir.mkdir()
    markdown_file = markdown_dir / "document.md"
    markdown_file.write_text("Direct text", encoding="utf-8")
    (markdown_dir / "rawki_passthrough.json").write_text(
        json.dumps(
            {
                "ingestion_mode": "direct_text",
                "external_document_id": "document-direct",
                "display_name": "Direct document",
                "metadata": {"assistant_id": "assistant-42"},
            }
        ),
        encoding="utf-8",
    )
    content = markdown_file.read_bytes()
    artifact = MarkdownArtifact(
        uri=str(markdown_file),
        relative_path="document.md",
        sha256=hashlib.sha256(content).hexdigest(),
        size_bytes=len(content),
        media_type="text/markdown",
        source_id="source-direct",
        document_id=document_id("source-direct", "document.md"),
        content_hash=hashlib.sha256(content).hexdigest(),
    )
    context = ArtifactPreparationContext(
        workflow_input={
            "source_id": "source-direct",
            "source_url": "https://example.test/direct-document",
            "dataset_id": "dataset-1",
            "job_id": "job-1",
            "task_id": "task-1",
        },
        options={"collection": "dataset-1"},
        markdown_dir=str(markdown_dir),
        artifact_store=LocalArtifactStore(tmp_path),
        artifacts_by_path={str(markdown_file): artifact},
    )

    prepared = prepare_artifact_batch([str(markdown_file)], context)

    assert prepared.documents[0].id == artifact.document_id
    assert prepared.documents[0].payload["ingestion_mode"] == "direct_text"
    assert prepared.documents[0].payload["display_name"] == "Direct document"
    assert prepared.documents[0].payload["metadata"] == {"assistant_id": "assistant-42"}

    chunks, _stats = prepare_documents(
        prepared.documents,
        chunk_chars=1000,
        chunk_overlap=0,
        default_job_id="job-1",
    )

    assert chunks[0]["doc_id"] == artifact.document_id
    assert chunks[0]["payload"]["source_identity"] == f"doc:{artifact.document_id}"
    assert chunks[0]["payload"]["source_url"] == (
        "https://example.test/direct-document"
    )


def test_prepare_direct_text_preserves_converter_like_markdown(tmp_path: Path) -> None:
    markdown_dir = tmp_path / "markdown"
    markdown_dir.mkdir()
    markdown_file = markdown_dir / "document.md"
    text = "| page | 7 |\n| --- | --- |\n\nActual document text"
    markdown_file.write_text(text, encoding="utf-8")
    (markdown_dir / "rawki_passthrough.json").write_text(
        json.dumps({"ingestion_mode": "direct_text"}),
        encoding="utf-8",
    )
    content = markdown_file.read_bytes()
    artifact = MarkdownArtifact(
        uri=str(markdown_file),
        relative_path="document.md",
        sha256=hashlib.sha256(content).hexdigest(),
        size_bytes=len(content),
        media_type="text/markdown",
        source_id="source-direct",
        document_id=document_id("source-direct", "document.md"),
        content_hash=hashlib.sha256(content).hexdigest(),
    )
    context = ArtifactPreparationContext(
        workflow_input={
            "source_id": "source-direct",
            "source_url": "external://document-direct",
            "dataset_id": "dataset-1",
            "job_id": "job-1",
            "task_id": "task-1",
        },
        options={"collection": "dataset-1"},
        markdown_dir=str(markdown_dir),
        artifact_store=LocalArtifactStore(tmp_path),
        artifacts_by_path={str(markdown_file): artifact},
    )

    prepared = prepare_artifact_batch([str(markdown_file)], context)

    assert prepared.documents[0].text == text
    chunks, stats = prepare_documents(
        prepared.documents,
        chunk_chars=1000,
        chunk_overlap=0,
        default_job_id="job-1",
    )
    assert stats["processed_docs"] == 1
    assert chunks[0]["content"].startswith("| page | 7 |")
