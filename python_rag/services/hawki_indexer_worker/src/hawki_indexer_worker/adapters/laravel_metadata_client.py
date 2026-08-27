"""Typed metadata references reported to Laravel through status callbacks."""

from __future__ import annotations

from pathlib import Path

from hawki_rag_contracts.pipeline.artifacts import ArtifactReference


def directory_reference(path: str) -> ArtifactReference:
    return ArtifactReference(uri=path, media_type="inode/directory")


def manifest_reference(path: str | None) -> ArtifactReference | None:
    if not path:
        return None
    resolved = Path(path.removeprefix("file://")).expanduser()
    size = resolved.stat().st_size if resolved.is_file() else None
    return ArtifactReference(
        uri=path,
        media_type="application/json",
        size_bytes=size,
    )


__all__ = ["directory_reference", "manifest_reference"]
