"""Python persistence adapter for the existing managed sink-writer protocol.

The indexer still uses ManagedWriterRegistration and its guarded adapters.
Like the Laravel owner, we store managed_writers/gate evidence with the source
operation, not in another writer registry. Unknown acknowledgements never expire.
"""

from __future__ import annotations

import hashlib
import copy
from pathlib import PurePosixPath
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field

from .identity import document_id
from hawki_rag_contracts.pipeline.identity import document_id as artifact_document_id


class WriterConflict(Exception):
    """Writer ownership, admission or release cannot be proved."""


class WriterTarget(BaseModel):
    model_config = ConfigDict(extra="forbid")
    doc_id: str = Field(min_length=1, max_length=191)
    relative_path: str = Field(min_length=1, max_length=2048)
    graph: bool = False


class WriterEvent(BaseModel):
    """The existing signed /managed-writers acquire/release wire contract."""

    model_config = ConfigDict(extra="forbid")
    schema_version: Literal[1]
    event_id: str = Field(min_length=1, max_length=255)
    action: Literal["acquire", "release", "uncertain"]
    source_id: str = Field(min_length=1, max_length=191)
    job_id: str = Field(min_length=1, max_length=191)
    workflow_id: str = Field(min_length=1, max_length=191)
    run_id: str = Field(min_length=1, max_length=191)
    activity_id: str = Field(min_length=1, max_length=191)
    attempt: int = Field(ge=1)
    targets: list[WriterTarget] = Field(max_length=1000)
    owner: Literal["url"] | None = None
    url_ingestion_operation_id: str | None = Field(
        default=None, min_length=1, max_length=64
    )
    dataset_id: str | None = Field(default=None, min_length=1, max_length=191)
    generation: int | None = Field(default=None, ge=1)

    def key(self) -> str:
        return hashlib.sha256(
            f"{self.workflow_id}|{self.run_id}|{self.activity_id}|{self.attempt}".encode()
        ).hexdigest()


def update_writer(
    row: dict[str, Any], event: WriterEvent, *, current_owner: bool
) -> tuple[dict[str, Any], dict[str, Any]]:
    """Apply the managed writer state machine while the source lock is held."""
    metadata = copy.deepcopy(row["request_metadata"])
    workflow_input = metadata.get("workflow_input", {})
    url = workflow_input.get("owner") == "url" and bool(
        workflow_input.get("url_ingestion_operation_id")
    )
    if url and (
        event.owner != "url"
        or event.url_ingestion_operation_id
        != workflow_input["url_ingestion_operation_id"]
        or event.dataset_id != row["dataset_id"]
        or event.generation != workflow_input.get("generation")
    ):
        raise WriterConflict()
    if not url and any(
        value is not None
        for value in (
            event.owner,
            event.url_ingestion_operation_id,
            event.dataset_id,
            event.generation,
        )
    ):
        raise WriterConflict()
    managed = (
        bool(workflow_input.get("managed_document_id")) or url
    ) and workflow_input.get("managed_writer_protocol") == 1
    if (
        row["source_id"] != event.source_id
        or row["job_id"] != event.job_id
        or row["workflow_id"] != event.workflow_id
        or (row["run_id"] is not None and row["run_id"] != event.run_id)
        or metadata.get("managed_writer_protocol") != 1
        or (not managed and workflow_input.get("text_writer_protocol") != 1)
    ):
        raise WriterConflict()
    writers = metadata.get("managed_writers", {})
    previous = writers.get(event.key())
    grants = []
    if event.action == "acquire":
        if (
            not current_owner
            or row["lifecycle_status"] not in {"accepted", "started"}
            or metadata.get("managed_writer_gate") != "open"
            or (previous and previous["status"] != "active")
            or (len(writers) >= 1000 and not previous)
            or any(
                k != event.key() and w["status"] != "released"
                for k, w in writers.items()
            )
        ):
            raise WriterConflict()
        scope = metadata["workflow_input"]["ingestion"]
        if not scope.get("collection") or (
            not managed and scope.get("graph") is not False
        ):
            raise WriterConflict()
        if managed and scope.get("graph") and not scope.get("neo4j_namespace"):
            raise WriterConflict()
        for target in event.targets:
            if url and target.graph and not scope.get("graph"):
                raise WriterConflict()
            if not managed and (
                target.graph
                or target.relative_path != "document.md"
                or target.doc_id != document_id(event.source_id)
            ):
                raise WriterConflict()
            if managed:
                path = PurePosixPath(target.relative_path)
                if (
                    path.is_absolute()
                    or not path.parts
                    or ".." in path.parts
                    or str(path) != target.relative_path
                    or target.doc_id
                    != artifact_document_id(event.source_id, target.relative_path)
                    or (target.graph and not scope.get("neo4j_namespace"))
                ):
                    raise WriterConflict()
                targets = metadata.setdefault("managed_targets", {})
                frozen = {
                    "relative_path": target.relative_path,
                    "graph": bool(scope.get("graph") or target.graph),
                }
                if target.doc_id in targets and targets[target.doc_id] != frozen:
                    raise WriterConflict()
                if len(targets) >= 1000 and target.doc_id not in targets:
                    raise WriterConflict()
                targets[target.doc_id] = frozen
            grants.append(
                {
                    "doc_id": target.doc_id,
                    "source_id": row["source_id"],
                    "dataset_id": row["dataset_id"],
                    "collection": scope["collection"],
                    "neo4j_namespace": scope.get("neo4j_namespace")
                    if managed and (scope.get("graph") or target.graph)
                    else None,
                }
            )
        if url:
            for grant in grants:
                grant.update(
                    owner="url",
                    url_ingestion_operation_id=workflow_input[
                        "url_ingestion_operation_id"
                    ],
                    generation=workflow_input["generation"],
                    neo4j_database=scope.get("neo4j_database"),
                )
        writers[event.key()] = {
            "workflow_id": event.workflow_id,
            "run_id": event.run_id,
            "activity_id": event.activity_id,
            "attempt": event.attempt,
            "job_id": event.job_id,
            "status": "active",
        }
        metadata["managed_writer_registered"] = True
    else:
        if previous is None:
            raise WriterConflict()
        # Reacquisition and late success can never clear uncertainty.
        if previous["status"] != "uncertain":
            writers[event.key()]["status"] = (
                "released" if event.action == "release" else "uncertain"
            )
    metadata["managed_writers"] = writers
    return metadata, {
        "success": True,
        "status": writers[event.key()]["status"],
        "targets": grants,
    }


def writers_released(metadata: dict[str, Any]) -> bool:
    """A fenced, protocol-enforced operation has no unacknowledged writer."""
    return (
        metadata.get("managed_writer_protocol") == 1
        and metadata.get("managed_writer_registered") is True
        and (
            metadata.get("workflow_input", {}).get("text_writer_protocol") == 1
            or (
                metadata.get("workflow_input", {}).get("managed_writer_protocol") == 1
                and (
                    bool(metadata.get("workflow_input", {}).get("managed_document_id"))
                    or (
                        metadata.get("workflow_input", {}).get("owner") == "url"
                        and bool(
                            metadata.get("workflow_input", {}).get(
                                "url_ingestion_operation_id"
                            )
                        )
                    )
                )
            )
        )
        and isinstance(metadata.get("managed_writers"), dict)
        and bool(metadata["managed_writers"])
        and all(
            writer.get("status") == "released"
            for writer in metadata["managed_writers"].values()
        )
    )
