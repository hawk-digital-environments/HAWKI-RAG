"""PostgreSQL locks serialize identity, publication, writer admission and cleanup.

Cleanup uses a session advisory lock, without an open transaction, across sink
RPCs. A crashed process releases it. A delayed cleaner cannot overlap a retry
or a later generation; a time based lease would not provide that guarantee.
"""

import copy
import json
import hashlib
from pathlib import Path
from collections.abc import Callable, Iterator
from contextlib import contextmanager
from typing import Any

import sqlalchemy as sa
from sqlalchemy.engine import Connection, Engine

from hawki_backend.text_ingestion.writers import (
    WriterConflict,
    WriterEvent,
    update_writer,
    writers_released,
)
from hawki_backend.workflow_control.schema import operations
from hawki_rag_contracts.pipeline.identity import document_id

from .input import ManagedError, digest
from .schema import documents, events, mutations, revisions


def _lock_key(value: str) -> int:
    return int.from_bytes(bytes.fromhex(digest(value))[:8], "big", signed=True)


def lock(connection: Connection, value: str) -> None:
    if connection.dialect.name == "postgresql":
        connection.execute(
            sa.text("SELECT pg_advisory_xact_lock(:key)"), {"key": _lock_key(value)}
        )


def one(connection: Connection, table: sa.Table, *conditions: Any) -> dict | None:
    row = (
        connection.execute(sa.select(table).where(*conditions).with_for_update())
        .mappings()
        .first()
    )
    return dict(row) if row else None


class ManagedRepository:
    def __init__(self, engine: Engine) -> None:
        self.engine = engine

    def read_rows(self, tenant: str) -> Iterator[dict]:
        with self.engine.connect() as connection:
            rows = (
                connection.execution_options(yield_per=100)
                .execute(sa.select(documents).where(documents.c.tenant_id == tenant))
                .mappings()
            )
            for row in rows:
                yield dict(row)

    def owned_ids(self, ids: list[str]) -> set[str]:
        # IDs are globally unique. Even a different-tenant owner must prevent
        # legacy projection; do not expose which tenant owns it.
        with self.engine.connect() as connection:
            return set(
                connection.execute(
                    sa.select(documents.c.managed_document_id).where(
                        documents.c.managed_document_id.in_(ids)
                    )
                ).scalars()
            )

    def document(self, tenant: str, document_id: str) -> dict:
        with self.engine.connect() as connection:
            row = (
                connection.execute(
                    sa.select(documents).where(
                        documents.c.tenant_id == tenant,
                        documents.c.managed_document_id == document_id,
                    )
                )
                .mappings()
                .first()
            )
        if row is None:
            raise ManagedError(404, "Document was not found.")
        return dict(row)

    def revision(self, revision_id: str) -> dict | None:
        with self.engine.connect() as connection:
            row = (
                connection.execute(
                    sa.select(revisions).where(revisions.c.revision_id == revision_id)
                )
                .mappings()
                .first()
            )
            return dict(row) if row else None

    def mutation(self, mutation_id: str) -> dict:
        with self.engine.connect() as connection:
            return dict(
                connection.execute(
                    sa.select(mutations).where(mutations.c.mutation_id == mutation_id)
                )
                .mappings()
                .one()
            )

    def save_receipt(self, mutation_id: str, status: int, payload: dict) -> None:
        with self.engine.begin() as connection:
            lock(connection, "managed-receipt:" + mutation_id)
            row = one(connection, mutations, mutations.c.mutation_id == mutation_id)
            if row["receipt"] is None or "payload" not in row["receipt"]:
                connection.execute(
                    mutations.update()
                    .where(mutations.c.mutation_id == mutation_id)
                    .values(receipt={"status": status, "payload": payload})
                )

    def claim(
        self,
        values: dict,
        build_candidate: Callable[[dict], tuple[dict, dict, dict]] | None,
        skip_reason: Callable[[dict], str | None] | None = None,
        *,
        expected_generation: int | None = None,
        expected_revision_id: str | None = None,
    ) -> dict:
        with self.engine.begin() as connection:
            lock(connection, "managed-key:" + values["mutation_id"])
            existing = one(
                connection, mutations, mutations.c.mutation_id == values["mutation_id"]
            )
            if existing:
                if existing["request_hash"] != values["request_hash"]:
                    raise ManagedError(
                        409,
                        "The idempotency key was already used with a different request.",
                    )
                return existing
            doc_id = values["managed_document_id"]
            lock(connection, "managed-document:" + doc_id)
            doc = one(
                connection,
                documents,
                documents.c.managed_document_id == doc_id,
                documents.c.tenant_id == values["tenant_id"],
            )
            if values["purpose"] == "create":
                if doc:
                    raise ManagedError(409, "Managed document identity conflict.")
                doc = dict(
                    managed_document_id=doc_id,
                    tenant_id=values["tenant_id"],
                    dataset_id=values["request"]["dataset_id"],
                    principal_id=values["principal_id"],
                    generation=0,
                    status="pending",
                    attributes={},
                )
                connection.execute(documents.insert().values(**doc))
            if doc is None:
                raise ManagedError(404, "Document was not found.")
            if (
                expected_generation is not None
                and doc["generation"] != expected_generation
            ):
                raise ManagedError(
                    409, "Managed generation changed; reload before retrying."
                )
            if (
                expected_revision_id is not None
                and (doc["pending_revision_id"] or doc["current_revision_id"])
                != expected_revision_id
            ):
                raise ManagedError(
                    409, "Managed revision changed; reload before retrying."
                )
            if doc.get("mutation_id"):
                active = one(
                    connection, mutations, mutations.c.mutation_id == doc["mutation_id"]
                )
                if (
                    active
                    and active["stage"] not in {"completed", "skipped"}
                    and active["purpose"] != "create"
                ):
                    raise ManagedError(
                        409, "Another managed mutation owns this document."
                    )
            values["expected_generation"] = doc["generation"]
            values["cleanup_snapshot"] = []
            reason = skip_reason(doc) if skip_reason else None
            doc["skip_reason"] = reason
            candidate = None
            if build_candidate is not None:
                doc["next_generation"] = (
                    connection.execute(
                        sa.select(sa.func.max(revisions.c.generation)).where(
                            revisions.c.managed_document_id == doc_id
                        )
                    ).scalar()
                    or 0
                ) + 1
                candidate, attributes, artifact = build_candidate(doc)
                values["request"] = {
                    "canonical": values["request"],
                    "candidate": candidate,
                    "attributes": attributes,
                    "artifact": artifact,
                }
                if reason:
                    # Metadata-only updates preserve content, graph and timestamp.
                    updated = copy.deepcopy(doc["attributes"])
                    for key in ("display_name", "source_url", "metadata_json"):
                        if key in values["request"]["canonical"]:
                            updated[key] = attributes[key]
                    connection.execute(
                        documents.update()
                        .where(documents.c.managed_document_id == doc_id)
                        .values(attributes=updated, updated_at=sa.func.now())
                    )
                    snapshot = doc | {"attributes": updated}
                    values.update(
                        stage="skipped",
                        receipt={
                            "reason": reason,
                            "document_snapshot": json.loads(
                                json.dumps(snapshot, default=str)
                            ),
                        },
                    )
                elif values["purpose"] == "create":
                    connection.execute(revisions.insert().values(**candidate))
                    values.update(
                        stage="prepared", revision_id=candidate["revision_id"]
                    )
                    connection.execute(
                        documents.update()
                        .where(documents.c.managed_document_id == doc_id)
                        .values(
                            pending_revision_id=candidate["revision_id"],
                            attributes=attributes,
                            mutation_id=values["mutation_id"],
                        )
                    )
            if values["purpose"] != "create" and not reason:
                values["request"]["expected_current_revision_id"] = doc.get(
                    "current_revision_id"
                )
                values["request"]["expected_pending_revision_id"] = doc.get(
                    "pending_revision_id"
                )
                owners = list(
                    connection.execute(
                        sa.select(revisions)
                        .where(
                            revisions.c.managed_document_id == doc_id,
                            revisions.c.status != "cleaned",
                        )
                        .order_by(revisions.c.generation)
                        .with_for_update()
                    ).mappings()
                )
                snapshot = []
                for owner in owners:
                    state = copy.deepcopy(owner["writer_state"])
                    state["managed_writer_gate"] = "closed"
                    state["fence_id"] = values["mutation_id"]
                    connection.execute(
                        revisions.update()
                        .where(revisions.c.revision_id == owner["revision_id"])
                        .values(writer_state=state)
                    )
                    snapshot.append(
                        {
                            "revision_id": owner["revision_id"],
                            "identity": self._identity(dict(owner)),
                        }
                    )
                values.update(stage="fenced", cleanup_snapshot=snapshot)
                connection.execute(
                    documents.update()
                    .where(documents.c.managed_document_id == doc_id)
                    .values(mutation_id=values["mutation_id"], status="deleting")
                )
            connection.execute(mutations.insert().values(**values))
            return dict(
                one(
                    connection,
                    mutations,
                    mutations.c.mutation_id == values["mutation_id"],
                )
            )

    def mark_artifact_published(self, mutation_id: str) -> None:
        with self.engine.begin() as connection:
            row = one(connection, mutations, mutations.c.mutation_id == mutation_id)
            request = copy.deepcopy(row["request"])
            request["artifact"]["state"] = "published"
            connection.execute(
                mutations.update()
                .where(mutations.c.mutation_id == mutation_id)
                .values(request=request, updated_at=sa.func.now())
            )

    @staticmethod
    def _identity(row: dict) -> str:
        # Mutable release/run acknowledgements are excluded; every sink target
        # and dispatch input is included. No acquired target may grow after fence.
        return digest(
            {
                key: row[key]
                for key in (
                    "revision_id",
                    "managed_document_id",
                    "tenant_id",
                    "dataset_id",
                    "generation",
                    "source_id",
                    "workflow_id",
                    "workflow_input",
                    "upload_path",
                )
            }
            | {"targets": row["writer_state"].get("managed_targets", {})}
        )

    def _check(
        self, connection: Connection, mutation: dict, *, drained: bool
    ) -> tuple[dict, list[dict]]:
        doc_id = mutation["managed_document_id"]
        lock(connection, "managed-document:" + doc_id)
        doc = one(connection, documents, documents.c.managed_document_id == doc_id)
        if (
            doc is None
            or doc["generation"] != mutation["expected_generation"]
            or doc["mutation_id"] != mutation["mutation_id"]
            or doc["current_revision_id"]
            != mutation["request"].get("expected_current_revision_id")
            or doc["pending_revision_id"]
            != mutation["request"].get("expected_pending_revision_id")
        ):
            raise ManagedError(
                409, "Managed generation changed; stale mutation rejected."
            )
        owners = []
        for entry in mutation["cleanup_snapshot"]:
            owner = one(
                connection, revisions, revisions.c.revision_id == entry["revision_id"]
            )
            if (
                owner is None
                or self._identity(owner) != entry["identity"]
                or owner["writer_state"].get("fence_id") != mutation["mutation_id"]
                or owner["writer_state"].get("managed_writer_gate") != "closed"
            ):
                raise ManagedError(409, "Cleanup ownership changed.")
            state = owner["writer_state"]
            empty_closed = (
                state.get("managed_no_writers_closed") is True
                and state.get("managed_writers") == {}
                and state.get("managed_writer_protocol") == 1
                and owner["workflow_input"].get("managed_writer_protocol") == 1
            )
            if (
                drained
                and not empty_closed
                and not writers_released(
                    state | {"workflow_input": owner["workflow_input"]}
                )
            ):
                raise ManagedError(
                    202, "Waiting for confirmed managed writer drainage."
                )
            owners.append(owner)
        return doc, owners

    @contextmanager
    def cleanup_owner(self, mutation: dict) -> Iterator[None]:
        connection = self.engine.connect()
        key = _lock_key("managed-cleanup:" + mutation["managed_document_id"])
        acquired = False
        try:
            if connection.dialect.name == "postgresql":
                acquired = connection.execute(
                    sa.text("SELECT pg_try_advisory_lock(:key)"), {"key": key}
                ).scalar_one()
                connection.commit()
                if not acquired:
                    raise ManagedError(202, "Managed cleanup is already pending.")
            yield
        finally:
            try:
                if acquired:
                    connection.execute(
                        sa.text("SELECT pg_advisory_unlock(:key)"), {"key": key}
                    )
                    connection.commit()
            except BaseException:
                connection.invalidate()
                raise
            finally:
                connection.close()

    def cleanup_snapshot(self, mutation: dict, *, drained: bool = True) -> list[dict]:
        with self.engine.begin() as connection:
            _, owners = self._check(connection, mutation, drained=drained)
            return owners

    def acknowledge_closed_without_writers(
        self, mutation: dict, revision_id: str
    ) -> None:
        """A fenced, known-closed run with an empty admission ledger wrote nothing.

        This never clears active or uncertain registrations. Closure by itself
        remains insufficient; the exact protocol and empty ledger are required.
        """
        with self.engine.begin() as connection:
            _, owners = self._check(connection, mutation, drained=False)
            for owner in owners:
                if (
                    owner["revision_id"] == revision_id
                    and owner["run_id"]
                    and owner["writer_state"].get("managed_writers") == {}
                ):
                    state = owner["writer_state"] | {"managed_no_writers_closed": True}
                    connection.execute(
                        revisions.update()
                        .where(revisions.c.revision_id == revision_id)
                        .values(writer_state=state)
                    )

    def advance(self, mutation: dict, stage: str) -> None:
        with self.engine.begin() as connection:
            self._check(connection, mutation, drained=True)
            connection.execute(
                mutations.update()
                .where(mutations.c.mutation_id == mutation["mutation_id"])
                .values(stage=stage, updated_at=sa.func.now())
            )

    def prepare_successor(self, mutation: dict) -> dict:
        with self.engine.begin() as connection:
            doc, owners = self._check(connection, mutation, drained=True)
            latest = one(
                connection,
                mutations,
                mutations.c.mutation_id == mutation["mutation_id"],
            )
            if latest["stage"] not in {
                "cleaned",
                "completed",
                "prepared",
                "dispatched",
            }:
                raise ManagedError(409, "Cleanup has not been verified.")
            if latest["stage"] != "cleaned":
                return latest
            for owner in owners:
                connection.execute(
                    revisions.update()
                    .where(revisions.c.revision_id == owner["revision_id"])
                    .values(status="cleaned")
                )
            fields = {"updated_at": sa.func.now()}
            if mutation["purpose"] == "delete":
                fields.update(status="deleted", pending_revision_id=None)
                final_doc = doc | {"status": "deleted", "pending_revision_id": None}
                request = latest["request"] | {
                    "deleted_document": json.loads(json.dumps(final_doc, default=str))
                }
                connection.execute(
                    mutations.update()
                    .where(mutations.c.mutation_id == mutation["mutation_id"])
                    .values(stage="completed", request=request)
                )
            else:
                candidate = latest["request"]["candidate"]
                connection.execute(revisions.insert().values(**candidate))
                fields.update(
                    status="processing", pending_revision_id=candidate["revision_id"]
                )
                connection.execute(
                    mutations.update()
                    .where(mutations.c.mutation_id == mutation["mutation_id"])
                    .values(stage="prepared", revision_id=candidate["revision_id"])
                )
            connection.execute(
                documents.update()
                .where(documents.c.managed_document_id == doc["managed_document_id"])
                .values(**fields)
            )
            return dict(
                one(
                    connection,
                    mutations,
                    mutations.c.mutation_id == mutation["mutation_id"],
                )
            )

    def record_dispatch(self, mutation: dict, run_id: str) -> None:
        with self.engine.begin() as connection:
            lock(connection, "managed-document:" + mutation["managed_document_id"])
            revision = one(
                connection,
                revisions,
                revisions.c.revision_id == mutation["revision_id"],
            )
            if revision["run_id"] and revision["run_id"] != run_id:
                raise ManagedError(409, "Workflow run identity changed.")
            if revision["status"] in {"prepared", "running"}:
                connection.execute(
                    documents.update()
                    .where(
                        documents.c.managed_document_id
                        == mutation["managed_document_id"],
                        documents.c.mutation_id == mutation["mutation_id"],
                        documents.c.pending_revision_id == revision["revision_id"],
                        documents.c.status.not_in(["deleted", "deleting"]),
                    )
                    .values(status="processing", updated_at=sa.func.now())
                )
            connection.execute(
                revisions.update()
                .where(revisions.c.revision_id == revision["revision_id"])
                .values(
                    run_id=run_id,
                    status="running"
                    if revision["status"] == "prepared"
                    else revision["status"],
                )
            )
            connection.execute(
                mutations.update()
                .where(
                    mutations.c.mutation_id == mutation["mutation_id"],
                    mutations.c.stage == "prepared",
                )
                .values(stage="dispatched")
            )

    def owner(self, connection: Connection, workflow_id: str) -> dict:
        candidates = list(
            connection.execute(
                sa.select(revisions).where(revisions.c.workflow_id == workflow_id)
            ).mappings()
        )
        operations_rows = list(
            connection.execute(
                sa.select(operations).where(
                    operations.c.workflow_id == workflow_id,
                    operations.c.action == "source",
                )
            ).mappings()
        )
        if len(candidates) != 1 or len(operations_rows) != 1:
            raise ManagedError(409, "Managed workflow ownership is unavailable.")
        revision = dict(candidates[0])
        wire = operations_rows[0]["payload"]["workflow_input"]
        if (
            not wire.get("managed_document_id")
            or wire["managed_document_id"] != revision["managed_document_id"]
            or wire != revision["workflow_input"]
            or any(
                wire.get(key) != revision[key]
                for key in ("source_id", "task_id", "job_id", "dataset_id")
            )
            or workflow_id != "ingest-source-" + revision["source_id"]
        ):
            raise ManagedError(409, "Managed workflow ownership is ambiguous.")
        return revision

    def record_writer(
        self, event: WriterEvent, payload_hash: str | None = None
    ) -> dict:
        payload_hash = payload_hash or digest(event.model_dump(mode="json"))
        with self.engine.begin() as connection:
            lock(connection, "managed-event:" + event.event_id)
            revision = self.owner(connection, event.workflow_id)
            lock(connection, "managed-document:" + revision["managed_document_id"])
            revision = one(
                connection,
                revisions,
                revisions.c.revision_id == revision["revision_id"],
            )
            previous = one(connection, events, events.c.event_id == event.event_id)
            if previous and previous["payload_hash"] != payload_hash:
                raise ManagedError(
                    409, "Worker event id was reused with a different payload."
                )
            doc = one(
                connection,
                documents,
                documents.c.managed_document_id == revision["managed_document_id"],
            )
            adapted = dict(
                revision,
                lifecycle_status={
                    "prepared": "accepted",
                    "running": "started",
                    "failed": "started",
                }.get(revision["status"], revision["status"]),
                request_metadata=revision["writer_state"]
                | {"workflow_input": revision["workflow_input"]},
            )
            try:
                metadata, receipt = update_writer(
                    adapted,
                    event,
                    current_owner=doc["pending_revision_id"] == revision["revision_id"]
                    and doc["generation"] == revision["expected_generation"]
                    and doc["mutation_id"] == revision["mutation_id"]
                    and doc["status"] != "deleted",
                )
            except WriterConflict:
                raise ManagedError(
                    409, "Managed writer admission or release could not be proved."
                ) from None
            metadata.pop("workflow_input", None)
            connection.execute(
                revisions.update()
                .where(revisions.c.revision_id == revision["revision_id"])
                .values(
                    writer_state=metadata, run_id=revision["run_id"] or event.run_id
                )
            )
            if not previous:
                connection.execute(
                    events.insert().values(
                        event_id=event.event_id,
                        payload_hash=payload_hash,
                        managed_document_id=revision["managed_document_id"],
                        revision_id=revision["revision_id"],
                        kind="writer",
                        receipt=receipt,
                    )
                )
            return receipt

    def complete(self, event: Any, payload_hash: str, status: str) -> dict:
        with self.engine.begin() as connection:
            lock(connection, "managed-event:" + event.event_id)
            previous = one(connection, events, events.c.event_id == event.event_id)
            if previous:
                if previous["payload_hash"] != payload_hash:
                    raise ManagedError(
                        409, "Worker event id was reused with a different payload."
                    )
                return previous["receipt"] | {"duplicate": True}
            revision = self.owner(connection, event.workflow_id)
            lock(connection, "managed-document:" + revision["managed_document_id"])
            revision = one(
                connection,
                revisions,
                revisions.c.revision_id == revision["revision_id"],
            )
            if (
                event.source_id != revision["source_id"]
                or event.job_id != revision["job_id"]
                or event.task_id != revision["task_id"]
                or revision["run_id"] not in {None, event.run_id}
            ):
                raise ManagedError(
                    409, "Managed event identity differs from the expected revision."
                )
            doc = one(
                connection,
                documents,
                documents.c.managed_document_id == revision["managed_document_id"],
            )
            ignored = (
                doc["pending_revision_id"] != revision["revision_id"]
                or doc["mutation_id"] != revision["mutation_id"]
                or doc["generation"] != revision["expected_generation"]
                or revision["writer_state"].get("managed_writer_gate") != "open"
                or doc["status"] in {"deleted", "deleting"}
                or revision["status"] in {"ready", "cleaned"}
            )
            receipt = {
                "event_id": event.event_id,
                "accepted": True,
                "duplicate": False,
                "ignored": ignored,
            }
            if not ignored:
                # Ready requires the existing writer's acknowledged manifest/drain
                # sequence. A workflow closure or a ready callback alone cannot publish.
                if status == "ready" and not writers_released(
                    revision["writer_state"]
                    | {"workflow_input": revision["workflow_input"]}
                ):
                    raise ManagedError(
                        409, "Managed publication requires released writers."
                    )
                completion = revision["completion"]
                if status in {"ready", "failed"}:
                    completion = event.model_dump(mode="json")
                if status == "ready":
                    completion["managed_manifest"] = self._manifest(revision, event)
                connection.execute(
                    revisions.update()
                    .where(revisions.c.revision_id == revision["revision_id"])
                    .values(
                        status=status,
                        run_id=event.run_id,
                        completion=completion,
                    )
                )
                if status == "ready":
                    mutation = one(
                        connection,
                        mutations,
                        mutations.c.mutation_id == revision["mutation_id"],
                    )
                    connection.execute(
                        documents.update()
                        .where(
                            documents.c.managed_document_id
                            == doc["managed_document_id"],
                            documents.c.generation == revision["expected_generation"],
                            documents.c.pending_revision_id == revision["revision_id"],
                        )
                        .values(
                            generation=revision["generation"],
                            current_revision_id=revision["revision_id"],
                            pending_revision_id=None,
                            status="indexed",
                            attributes=mutation["request"]["attributes"],
                            updated_at=sa.func.now(),
                        )
                    )
                    connection.execute(
                        mutations.update()
                        .where(mutations.c.mutation_id == revision["mutation_id"])
                        .values(stage="completed")
                    )
                elif status == "failed":
                    connection.execute(
                        documents.update()
                        .where(
                            documents.c.managed_document_id
                            == doc["managed_document_id"]
                        )
                        .values(status="failed")
                    )
                    connection.execute(
                        mutations.update()
                        .where(mutations.c.mutation_id == revision["mutation_id"])
                        .values(stage="completed")
                    )
            connection.execute(
                events.insert().values(
                    event_id=event.event_id,
                    payload_hash=payload_hash,
                    managed_document_id=revision["managed_document_id"],
                    revision_id=revision["revision_id"],
                    kind="completion",
                    receipt=receipt,
                )
            )
            return receipt

    @staticmethod
    def _manifest(revision: dict, event: Any) -> dict:
        expected = revision["workflow_input"]["ingest_manifest_path"]
        if (
            event.manifest is None
            or event.manifest.uri.removeprefix("file://") != expected
        ):
            raise ManagedError(409, "Managed completion manifest identity differs.")
        try:
            with Path(expected).open("rb") as stream:
                raw = stream.read(8 * 1024 * 1024 + 1)
            if len(raw) > 8 * 1024 * 1024:
                raise ValueError()
            records = json.loads(raw)
            if not isinstance(records, list) or len(records) > 1000:
                raise ValueError()
            ids = set()
            root = Path(revision["workflow_input"]["markdown_output_path"])
            for record in records:
                relative = record["relative_path"]
                if (
                    record["document_id"]
                    != document_id(revision["source_id"], relative)
                    or record["document_id"] in ids
                    or Path(record["markdown_path"]) != root / relative
                ):
                    raise ValueError()
                ids.add(record["document_id"])
            if not set(revision["writer_state"].get("managed_targets", {})).issubset(
                ids
            ):
                raise ValueError()
        except (OSError, KeyError, TypeError, ValueError):
            raise ManagedError(
                409, "Managed completion manifest could not be verified."
            ) from None
        return {"sha256": hashlib.sha256(raw).hexdigest(), "records": records}
