"""Immutable historical evidence and reservations, never active owner authority."""

from collections.abc import Mapping
import hashlib
import json
import os
from pathlib import Path

from fastapi import HTTPException


def validate_record(record: dict) -> None:
    """Refuse an archive that could be mistaken for a completion or tenant grant."""
    if (
        record.get("schema_version") != 1
        or record.get("category") != "unresolved_legacy_deletion"
        or record.get("deletion_completion") != "UNVERIFIED"
        or record.get("tenant_custody") != "UNKNOWN"
        or record.get("dataset_custody") != "UNKNOWN"
        or "active_runtime_owner" not in record
        or record["active_runtime_owner"] is not None
        or record.get("preservation") != "in_place"
        or not record.get("archived_at")
        or not isinstance(record.get("reserved_identifiers"), dict)
        or not record["reserved_identifiers"]
        or not record.get("files")
        or not record.get("legacy_rows")
    ):
        raise ValueError("Invalid historical evidence record")
    for kind, values in record["reserved_identifiers"].items():
        if not isinstance(kind, str) or not isinstance(values, list) or not values:
            raise ValueError("Invalid historical reservation")
        if any(not isinstance(value, str) or not value for value in values):
            raise ValueError("Invalid historical identifier")


def create_archive(directory: Path, record: dict) -> Path:
    """Create a content-addressed record exclusively; existing bytes never change."""
    validate_record(record)
    raw = (
        json.dumps(record, sort_keys=True, ensure_ascii=True, indent=2) + "\n"
    ).encode()
    directory.mkdir(mode=0o750, parents=True, exist_ok=True)
    destination = directory / (hashlib.sha256(raw).hexdigest() + ".json")
    descriptor = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o640)
    with os.fdopen(descriptor, "wb") as stream:
        stream.write(raw)
        stream.flush()
        os.fsync(stream.fileno())
    descriptor = os.open(directory, os.O_RDONLY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)
    return destination


def require_unreserved(identifiers: Mapping[str, object]) -> None:
    """Fence new admission/dispatch; archives cannot authorize reads or cleanup."""
    configured = os.environ.get("RAG_BACKEND_HISTORICAL_ARCHIVE_ROOT")
    if not configured:
        return
    try:
        root = Path(configured)
        if not root.is_absolute() or not root.is_dir():
            raise ValueError("Archive storage is unavailable")
        records = []
        for path in sorted(root.glob("*.json")):
            if path.is_symlink():
                raise ValueError("Archive link refused")
            raw = path.read_bytes()
            if hashlib.sha256(raw).hexdigest() != path.stem:
                raise ValueError("Archive integrity mismatch")
            record = json.loads(raw)
            validate_record(record)
            records.append(record)
        if not records:
            raise ValueError("Configured archive has no evidence records")
    except (OSError, ValueError, TypeError, AttributeError):
        raise HTTPException(503, "historical_archive_unavailable") from None
    for record in records:
        for kind, values in record["reserved_identifiers"].items():
            if identifiers.get(kind) in values:
                raise HTTPException(409, "historical_identity_reserved")
