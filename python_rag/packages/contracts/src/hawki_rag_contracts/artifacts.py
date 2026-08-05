"""Typed references to artifacts exchanged by ingestion workers."""

from __future__ import annotations

from typing import Literal

from pydantic import BaseModel, ConfigDict, Field


_SHA256_PATTERN = r"^[0-9a-f]{64}$"


class ArtifactReference(BaseModel):
    """Portable reference to immutable artifact content."""

    model_config = ConfigDict(extra="forbid", frozen=True, str_strip_whitespace=True)

    uri: str = Field(min_length=1, max_length=4096)
    relative_path: str | None = Field(default=None, max_length=2048)
    sha256: str | None = Field(default=None, pattern=_SHA256_PATTERN)
    size_bytes: int | None = Field(default=None, ge=0)
    media_type: str | None = Field(default=None, max_length=255)


class RawArtifact(ArtifactReference):
    """Raw source artifact produced by the scraper worker."""

    kind: Literal["raw"] = "raw"
    source_id: str = Field(min_length=1, max_length=191)
    source_url: str | None = Field(default=None, max_length=4096)
    original_name: str | None = Field(default=None, max_length=1024)


class MarkdownArtifact(ArtifactReference):
    """Normalized Markdown artifact produced by the converter worker."""

    kind: Literal["markdown"] = "markdown"
    source_id: str = Field(min_length=1, max_length=191)
    document_id: str = Field(min_length=1, max_length=191)
    content_hash: str = Field(pattern=_SHA256_PATTERN)
    source_artifact_uri: str | None = Field(default=None, max_length=4096)


__all__ = ["ArtifactReference", "MarkdownArtifact", "RawArtifact"]
