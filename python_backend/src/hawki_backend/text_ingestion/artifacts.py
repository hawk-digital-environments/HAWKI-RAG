"""Immutable Markdown artifacts for direct-text ingestion.

The layout and the permissions are part of the existing operational contract:

    <shared_root>/sources/<source_id>/revisions/<fingerprint>/markdown/document.md

``document.md`` and its companion ``rawki_passthrough.json`` are written once
and never replaced. A crash after the write but before the operation row is
committed leaves a durable ``reconciliation-required.json`` marker next to
the revision, exactly as the legacy implementation did.
"""

from __future__ import annotations

import json
import logging
import os
import secrets
from dataclasses import dataclass
from typing import Any

from . import identity

logger = logging.getLogger(__name__)

_DIRECTORY_MODE = 0o2775
_FILE_MODE = 0o664
_RECONCILIATION_MARKER = "reconciliation-required.json"
_INGESTION_MODE = "direct_text"


class TextArtifactError(RuntimeError):
    """Raised when an immutable artifact cannot be stored or verified."""


@dataclass(frozen=True, slots=True)
class StoredTextArtifact:
    """The immutable artifact identity of one direct-text revision."""

    source_id: str
    source_url: str
    content_hash: str
    markdown_path: str


class TextArtifactStorage:
    """Store and verify immutable direct-text revisions."""

    def __init__(self, shared_root: str) -> None:
        self._root = shared_root.rstrip("/")
        if not self._root or not self._root.startswith("/"):
            raise TextArtifactError("the shared storage root must be absolute")

    def store(
        self,
        *,
        dataset_id: str,
        external_document_id: str,
        fingerprint: str,
        text: str,
        content_format: str,
        display_name: str | None,
        source_url: str | None,
        metadata: dict[str, Any] | None,
    ) -> StoredTextArtifact:
        """Write the revision, accepting an identical existing revision."""
        source = identity.source_id(dataset_id, external_document_id)
        source_directory = os.path.join(self._root, "sources", source)
        revision_directory = os.path.join(source_directory, "revisions", fingerprint)
        markdown_directory = os.path.join(revision_directory, "markdown")
        markdown_path = os.path.join(markdown_directory, "document.md")
        metadata_path = os.path.join(markdown_directory, "rawki_passthrough.json")
        payload = _pretty_json(
            {
                "ingestion_mode": _INGESTION_MODE,
                "external_document_id": external_document_id,
                "display_name": display_name,
                "content_format": content_format,
                # PHP encodes the validated metadata array. An absent or empty
                # array is the JSON list ``[]``, not an empty object.
                "metadata": metadata or [],
            }
        )

        artifact = StoredTextArtifact(
            source_id=source,
            source_url=source_url or f"external://{external_document_id}",
            content_hash=identity.content_hash(text),
            markdown_path=markdown_path,
        )

        try:
            for directory in (
                source_directory,
                os.path.join(source_directory, "revisions"),
                revision_directory,
                markdown_directory,
            ):
                self._ensure_directory(directory)
            # Publish the marker first: a process death during either artifact
            # write must leave evidence even before the database claim commits.
            self.mark_for_reconciliation(artifact)
            self._write_immutable(markdown_path, text)
            self._write_immutable(
                metadata_path,
                payload + "\n",
                canonical_json=True,
            )
        except TextArtifactError:
            raise
        except OSError as error:
            raise TextArtifactError(
                f"the direct-text artifact could not be stored: {markdown_path}"
            ) from error

        return artifact

    def mark_for_reconciliation(self, artifact: StoredTextArtifact) -> None:
        """Write the durable marker for a write/persist crash window."""
        marker_path = self._marker_path(artifact)
        marker = _pretty_json(
            {
                "source_id": artifact.source_id,
                "content_hash": artifact.content_hash,
                "markdown_path": artifact.markdown_path,
            }
        )
        self._write_immutable(marker_path, marker + "\n")

    def clear_reconciliation_marker(self, artifact: StoredTextArtifact) -> None:
        try:
            os.unlink(self._marker_path(artifact))
        except FileNotFoundError:
            return
        except OSError:
            logger.warning(
                "direct-text reconciliation marker could not be cleared source_id=%s",
                artifact.source_id,
            )

    def delete_source_artifacts(
        self, source_id: str, *, markdown_path: str | None = None
    ) -> None:
        """Delete one owned source directory, refusing anything outside root."""
        if not _valid_source_id(source_id):
            raise TextArtifactError("the direct-text source id is invalid")
        root = self._root
        if markdown_path is not None:
            import re

            match = re.fullmatch(
                r"(.+)/sources/"
                + re.escape(source_id)
                + r"/revisions/[0-9a-f]{64}/markdown/document\.md",
                markdown_path,
            )
            if match is None:
                raise TextArtifactError("the selected artifact identity is invalid")
            root = match.group(1)
        sources_directory = os.path.join(root, "sources")
        source_directory = os.path.join(sources_directory, source_id)
        if not os.path.exists(source_directory):
            return

        resolved_sources = os.path.realpath(sources_directory)
        resolved_source = os.path.realpath(source_directory)
        if not resolved_source.startswith(resolved_sources + os.sep) or os.path.islink(
            source_directory
        ):
            raise TextArtifactError("the artifact path is outside shared storage")

        _remove_tree(source_directory)

    def _ensure_directory(self, path: str) -> None:
        os.makedirs(path, mode=_DIRECTORY_MODE, exist_ok=True)
        os.chmod(path, _DIRECTORY_MODE)

    def _write_immutable(
        self,
        path: str,
        contents: str,
        *,
        canonical_json: bool = False,
    ) -> None:
        data = contents.encode("utf-8")
        if os.path.exists(path):
            self._assert_stored(path, data, canonical_json=canonical_json)
            return

        temporary = f"{path}.tmp.{secrets.token_hex(8)}"
        try:
            with open(temporary, "wb") as handle:
                handle.write(data)
                handle.flush()
                os.fsync(handle.fileno())
            os.chmod(temporary, _FILE_MODE)
            try:
                os.link(temporary, path)
            except FileExistsError:
                self._assert_stored(path, data, canonical_json=canonical_json)
            except OSError as error:
                # Exclusive create exposes partial bytes to readers. Fail closed
                # when this shared filesystem cannot atomically publish a link.
                raise TextArtifactError("atomic artifact publication failed") from error
        finally:
            try:
                os.unlink(temporary)
            except FileNotFoundError:
                pass

    @staticmethod
    def _assert_stored(
        path: str,
        expected: bytes,
        *,
        canonical_json: bool = False,
    ) -> None:
        with open(path, "rb") as handle:
            stored = handle.read()
        if canonical_json:
            if _same_canonical_json(stored, expected):
                return
        elif stored == expected:
            return
        raise TextArtifactError(
            "an immutable artifact already exists with different contents"
        )

    @staticmethod
    def _marker_path(artifact: StoredTextArtifact) -> str:
        revision = os.path.dirname(os.path.dirname(artifact.markdown_path))
        return os.path.join(revision, _RECONCILIATION_MARKER)


def _valid_source_id(value: str) -> bool:
    if not value.startswith("source_") or len(value) != len("source_") + 32:
        return False
    return all(character in "0123456789abcdef" for character in value[len("source_") :])


def _pretty_json(value: Any) -> str:
    return identity.legacy_json(value, pretty=True)


def _same_canonical_json(left: bytes, right: bytes) -> bool:
    """Compare two JSON documents by value, mirroring the legacy check."""
    try:
        return _canonical(left) == _canonical(right)
    except (TypeError, ValueError, UnicodeDecodeError):
        return False


def _canonical(value: bytes) -> str:
    return json.dumps(
        json.loads(value.decode("utf-8")),
        sort_keys=True,
        separators=(",", ":"),
        ensure_ascii=True,
    )


def _remove_tree(path: str) -> None:
    for entry in os.scandir(path):
        if entry.is_dir(follow_symlinks=False):
            _remove_tree(entry.path)
        else:
            os.unlink(entry.path)
    os.rmdir(path)


__all__ = [
    "StoredTextArtifact",
    "TextArtifactError",
    "TextArtifactStorage",
]
