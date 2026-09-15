"""Deterministic identities for Qdrant chunks and document completion."""

from __future__ import annotations

import hashlib
import uuid
from collections.abc import Iterable

_POINT_NAMESPACE = uuid.NAMESPACE_URL
_COMPLETION_SCHEMA_VERSION = "1"


def deterministic_point_id(doc_id: str, chunk_index: int) -> str:
    """Return the stable Qdrant point ID for one document chunk."""

    return str(uuid.uuid5(_POINT_NAMESPACE, f"{doc_id}:{chunk_index}"))


def document_completion_fingerprint(
    doc_id: str,
    content_hash: str,
    point_ids: Iterable[str],
) -> str:
    """Fingerprint the exact deterministic point set for one document revision."""

    shape = "\0".join(
        (
            _COMPLETION_SCHEMA_VERSION,
            doc_id,
            content_hash,
            *sorted(str(point_id) for point_id in point_ids),
        )
    )
    return hashlib.sha256(shape.encode("utf-8")).hexdigest()


__all__ = ["deterministic_point_id", "document_completion_fingerprint"]
