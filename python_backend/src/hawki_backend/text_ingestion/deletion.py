"""Immutable identity selected under the source deletion/admission lock."""

from dataclasses import dataclass
import hashlib
import json
from typing import Any


def owner_identity(row: dict[str, Any]) -> str:
    """Exclude mutable writer receipts and include every cleanup/dispatch input."""
    return hashlib.sha256(
        json.dumps(
            {
                key: row[key]
                for key in (
                    "tenant_id",
                    "dataset_id",
                    "idempotency_key",
                    "source_id",
                    "fingerprint",
                    "content_hash",
                    "markdown_path",
                    "workflow_id",
                    "run_id",
                    "task_id",
                    "job_id",
                )
            }
            | {"workflow_input": row["request_metadata"].get("workflow_input")},
            sort_keys=True,
            separators=(",", ":"),
        ).encode()
    ).hexdigest()


@dataclass(frozen=True, slots=True)
class DeletionSnapshot:
    tenant_id: str
    dataset_id: str
    source_id: str
    document_id: str
    idempotency_key: str
    workflow_id: str
    run_id: str | None
    collection: str
    markdown_path: str
    deletion_key: str
    fence_id: str
    owners: tuple[tuple[str, str], ...]
