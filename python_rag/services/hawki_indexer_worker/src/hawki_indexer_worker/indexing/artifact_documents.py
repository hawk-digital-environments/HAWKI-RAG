"""Prepare verified Markdown artifacts for index batch execution."""

from __future__ import annotations

import hashlib
from dataclasses import dataclass
from typing import Any, NotRequired, TypedDict

from hawki_artifact_store.local import LocalArtifactStore
from hawki_rag_contracts.pipeline.artifacts import MarkdownArtifact
from hawki_rag_contracts.pipeline.identity import document_id, sha256_text
from hawki_rag_text.markdown import strip_leading_converter_markdown_noise

from hawki_indexer_worker.adapters.artifact_store import load_passthrough_metadata
from hawki_indexer_worker.domain.models import IngestDocument


class IngestManifestRecord(TypedDict):
    """Stable manifest fields produced for one Markdown artifact."""

    document_id: str
    relative_path: str
    content_hash: str
    markdown_path: str
    passthrough: NotRequired[dict[str, Any]]


@dataclass(frozen=True, slots=True)
class ArtifactPreparationContext:
    """Inputs shared while converting one batch of Markdown artifacts."""

    workflow_input: dict[str, Any]
    options: dict[str, Any]
    markdown_dir: str
    artifact_store: LocalArtifactStore
    artifacts_by_path: dict[str, MarkdownArtifact]


@dataclass(frozen=True, slots=True)
class PreparedArtifactBatch:
    """Indexable documents and manifest records prepared from one file batch."""

    documents: list[IngestDocument]
    manifest_records: list[IngestManifestRecord]
    skipped_documents: int


def prepare_artifact_batch(
    markdown_files: list[str],
    context: ArtifactPreparationContext,
) -> PreparedArtifactBatch:
    """Verify and normalize one batch of Markdown artifact paths."""

    documents: list[IngestDocument] = []
    manifest_records: list[IngestManifestRecord] = []
    skipped_documents = 0

    for markdown_location in markdown_files:
        markdown_file = str(context.artifact_store.resolve(markdown_location))
        content = context.artifact_store.read_bytes(markdown_file)
        decoded_text = content.decode("utf-8")
        artifact = context.artifacts_by_path.get(markdown_file)
        text = _artifact_text(decoded_text, artifact)
        if not text.strip():
            skipped_documents += 1
            continue
        document, record = _document_from_artifact(
            context,
            markdown_file=markdown_file,
            text=text,
            content=content,
            artifact=artifact,
        )
        documents.append(document)
        manifest_records.append(record)

    return PreparedArtifactBatch(
        documents=documents,
        manifest_records=manifest_records,
        skipped_documents=skipped_documents,
    )


def _artifact_text(
    decoded_text: str,
    artifact: MarkdownArtifact | None,
) -> str:
    """Preserve explicitly hashed text and clean only converter-style artifacts."""

    if artifact is not None and artifact.content_hash == sha256_text(decoded_text):
        return decoded_text
    return strip_leading_converter_markdown_noise(decoded_text)


def _document_from_artifact(
    context: ArtifactPreparationContext,
    *,
    markdown_file: str,
    text: str,
    content: bytes,
    artifact: MarkdownArtifact | None,
) -> tuple[IngestDocument, IngestManifestRecord]:
    workflow_input = context.workflow_input
    source_id = str(workflow_input["source_id"])
    relative_path = context.artifact_store.relative_path(
        markdown_file,
        context.markdown_dir,
    )
    doc_id = document_id(source_id, relative_path)
    content_hash = sha256_text(text)
    if artifact is not None:
        _verify_artifact(
            artifact,
            source_id=source_id,
            relative_path=relative_path,
            document_id_value=doc_id,
            content_hash=content_hash,
            content=content,
        )
        doc_id = artifact.document_id
        content_hash = artifact.content_hash

    allowed_metadata_directories = [context.markdown_dir]
    raw_output_path = workflow_input.get("raw_output_path")
    if isinstance(raw_output_path, str) and raw_output_path.strip():
        allowed_metadata_directories.append(raw_output_path)

    passthrough = load_passthrough_metadata(
        context.artifact_store,
        markdown_file,
        allowed_directories=allowed_metadata_directories,
    )
    payload = dict(passthrough)
    payload.update(
        {
            "managed_document_id": workflow_input.get("managed_document_id"),
            "dataset_id": workflow_input.get("dataset_id"),
            "source_id": source_id,
            "document_id": doc_id,
            "doc_id": doc_id,
            "chunk_id": None,
            "version": content_hash[:16],
            "url": workflow_input.get("source_url"),
            "source_url": workflow_input.get("source_url"),
            "source_format": "markdown",
            "relative_path": relative_path,
            "content_hash": content_hash,
            "job_id": workflow_input.get("job_id"),
            "task_id": workflow_input.get("task_id"),
            "qdrant_collection": context.options.get("collection"),
            "neo4j_namespace": context.options.get("neo4j_namespace"),
        }
    )
    record = IngestManifestRecord(
        document_id=doc_id,
        relative_path=relative_path,
        content_hash=content_hash,
        markdown_path=markdown_file,
    )
    if passthrough:
        record["passthrough"] = passthrough
    return IngestDocument(id=doc_id, text=text, payload=payload), record


def _verify_artifact(
    artifact: MarkdownArtifact,
    *,
    source_id: str,
    relative_path: str,
    document_id_value: str,
    content_hash: str,
    content: bytes,
) -> None:
    mismatches: list[str] = []
    if artifact.source_id != source_id:
        mismatches.append("source_id")
    if artifact.relative_path is not None and artifact.relative_path != relative_path:
        mismatches.append("relative_path")
    if artifact.document_id != document_id_value:
        mismatches.append("document_id")
    if artifact.content_hash != content_hash:
        mismatches.append("content_hash")
    if (
        artifact.sha256 is not None
        and artifact.sha256 != hashlib.sha256(content).hexdigest()
    ):
        mismatches.append("sha256")
    if artifact.size_bytes is not None and artifact.size_bytes != len(content):
        mismatches.append("size_bytes")
    if mismatches:
        fields = ", ".join(mismatches)
        raise RuntimeError(
            f"Converted artifact metadata does not match {artifact.uri}: {fields}"
        )


__all__ = [
    "ArtifactPreparationContext",
    "IngestManifestRecord",
    "PreparedArtifactBatch",
    "prepare_artifact_batch",
]
