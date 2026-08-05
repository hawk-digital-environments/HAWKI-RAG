"""Build canonical Markdown artifact references after conversion."""

from __future__ import annotations

import hashlib

from hawki_artifact_store.identity import document_id, sha256_text
from hawki_artifact_store.local import LocalArtifactStore
from hawki_rag_contracts.artifacts import MarkdownArtifact
from hawki_rag_text.markdown import strip_leading_converter_markdown_noise


def collect_markdown_artifacts(
    markdown_dir: str,
    *,
    source_id: str,
    source_artifact_uri: str | None,
    artifact_store: LocalArtifactStore,
) -> list[MarkdownArtifact]:
    """Describe converted local Markdown files without embedding their content."""

    artifacts: list[MarkdownArtifact] = []
    for location in artifact_store.list_markdown(markdown_dir):
        content = artifact_store.read_bytes(location)
        normalized_text = strip_leading_converter_markdown_noise(
            content.decode("utf-8")
        )
        relative_path = artifact_store.relative_path(location, markdown_dir)
        artifacts.append(
            MarkdownArtifact(
                uri=location,
                relative_path=relative_path,
                sha256=hashlib.sha256(content).hexdigest(),
                size_bytes=len(content),
                media_type="text/markdown",
                source_id=source_id,
                document_id=document_id(source_id, relative_path),
                content_hash=sha256_text(normalized_text),
                source_artifact_uri=source_artifact_uri,
            )
        )
    return artifacts


__all__ = ["collect_markdown_artifacts"]
