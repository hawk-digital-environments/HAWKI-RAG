"""Source-relative artifact identity, independent of descriptive URLs."""

from collections.abc import Mapping
from typing import Any

from hawki_rag_contracts.pipeline.identity import document_id

from hawki_indexer_worker.domain.errors import IndexingValidationError


def has_artifact_identity(payload: Mapping[str, Any]) -> bool:
    """Recognize prepared artifacts and older source/path-backed inputs."""

    # Direct text has its own source ownership and legacy-recovery contract.
    if payload.get("ingestion_mode") == "direct_text":
        return False
    return payload.get("document_identity") == "source_path" or (
        "source_id" in payload and "relative_path" in payload
    )


def validate_artifact_identity(payload: Mapping[str, Any], doc_id: str) -> None:
    """Reject incomplete or conflicting artifact identity before any writes."""

    source_id = payload.get("source_id")
    relative_path = payload.get("relative_path")
    if not isinstance(source_id, str) or not source_id.strip():
        raise IndexingValidationError("Artifact source_id must be a nonempty string")
    if not isinstance(relative_path, str) or not relative_path.strip():
        raise IndexingValidationError(
            "Artifact relative_path must be a nonempty string"
        )
    try:
        expected = document_id(source_id, relative_path)
    except ValueError as exc:
        raise IndexingValidationError(str(exc)) from exc
    ids = [
        doc_id,
        *(payload[key] for key in ("doc_id", "document_id") if key in payload),
    ]
    if any(value != expected for value in ids):
        raise IndexingValidationError(
            "Artifact document ID does not match source_id and relative_path"
        )


def matches_artifact_owner(
    existing: Mapping[str, Any], payload: Mapping[str, Any]
) -> bool:
    """Legacy URL identities cannot authorize replacement of an artifact."""

    return all(
        existing.get(key) == payload.get(key)
        for key in ("doc_id", "source_id", "relative_path")
    )
