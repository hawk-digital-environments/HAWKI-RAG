"""Indexer-facing local artifact operations."""

from __future__ import annotations

import json
import logging
from collections.abc import Iterable
from pathlib import Path
from typing import Any

from hawki_artifact_store.local import LocalArtifactStore

PASSTHROUGH_METADATA_FILENAME = "rawki_passthrough.json"
LOCAL_PATH_FIELDS = (
    "original_path",
    "source_file",
    "file_path",
    "converted_path",
    "image_path",
)
logger = logging.getLogger(__name__)


def load_passthrough_metadata(
    artifact_store: LocalArtifactStore,
    markdown_file: str,
    *,
    allowed_directories: Iterable[str | Path],
) -> dict[str, Any]:
    """Load converter fallback metadata beside a Markdown artifact."""

    markdown_path = artifact_store.resolve(markdown_file)
    metadata_path = artifact_store.resolve(
        markdown_path.parent / PASSTHROUGH_METADATA_FILENAME
    )
    artifact_store.relative_path(metadata_path, markdown_path.parent)
    if not metadata_path.is_file():
        return {}
    try:
        payload = json.loads(artifact_store.read_text(metadata_path))
    except (OSError, json.JSONDecodeError) as exc:
        logger.warning(
            "indexer:passthrough_metadata unreadable path=%s error=%s",
            metadata_path,
            exc,
        )
        return {}
    if not isinstance(payload, dict):
        return {}
    metadata = {
        str(key): value
        for key, value in payload.items()
        if isinstance(key, str) and key.strip()
    }
    allowed_roots = [artifact_store.resolve(path) for path in allowed_directories]
    for key in LOCAL_PATH_FIELDS:
        value = metadata.get(key)
        if isinstance(value, str) and value.strip():
            metadata[key] = _allowed_local_path(
                artifact_store,
                value,
                allowed_roots,
            )

    images = metadata.get("images")
    if isinstance(images, list):
        metadata["images"] = [
            _allowed_local_path(artifact_store, value, allowed_roots)
            for value in images
            if isinstance(value, str) and value.strip()
        ]
    return metadata


def _allowed_local_path(
    artifact_store: LocalArtifactStore,
    value: str,
    allowed_roots: list[Path],
) -> str:
    path = artifact_store.resolve(value)
    if not any(path == root or path.is_relative_to(root) for root in allowed_roots):
        raise ValueError(
            f"Passthrough metadata path is outside its artifact directories: {path}"
        )
    return str(path)
