"""Persistence of direct-text operations, outside SQL locks.

Every method opens one short transaction. No transaction or connection is
held across a network call, so workflow dispatch or Qdrant cleanup can never
receive a live database connection.
"""

from __future__ import annotations

import hashlib
import uuid
from collections.abc import Callable
from datetime import datetime, timezone
from typing import Any

import sqlalchemy as sa
from sqlalchemy.engine import Engine
from sqlalchemy.exc import IntegrityError

from .deletion import DeletionSnapshot, owner_identity
from .identity import document_id
from .writers import WriterConflict, WriterEvent, update_writer, writers_released

from .schema import (
    STATUS_ACCEPTED,
    STATUS_DELETED,
    STATUS_DELETE_FAILED,
    STATUS_DELETING,
    STATUS_FAILED,
    STATUS_READY,
    STATUS_STARTED,
    ingest_text_events,
    ingest_text_operations,
)

_ACTIVE_STATUSES = (
    STATUS_FAILED,
    STATUS_ACCEPTED,
    STATUS_STARTED,
    STATUS_DELETING,
    STATUS_DELETE_FAILED,
)


class OperationConflict(Exception):
    """The idempotency key was replayed with a different canonical request."""


class OperationNotFound(Exception):
    """No owned direct-text operation matches the request."""


class SourceBusy(Exception):
    """The source has an unresolved owner, so it cannot be replaced or deleted."""


class EventCollision(Exception):
    """One event id was reused with a different signed payload."""


def _now() -> datetime:
    return datetime.now(timezone.utc)


def _deletion_digest(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


class TextOperationRepository:
    """Read and mutate the Python-owned direct-text rows."""

    def __init__(self, engine: Engine) -> None:
        self._engine = engine

    def find(self, tenant_id: str, idempotency_key: str) -> dict[str, Any] | None:
        with self._engine.connect() as connection:
            row = (
                connection.execute(
                    sa.select(ingest_text_operations)
                    .where(
                        ingest_text_operations.c.tenant_id == tenant_id,
                        ingest_text_operations.c.idempotency_key == idempotency_key,
                    )
                    .limit(1)
                )
                .mappings()
                .first()
            )
        return dict(row) if row is not None else None

    def find_by_source(self, tenant_id: str, source_id: str) -> dict[str, Any] | None:
        with self._engine.connect() as connection:
            row = (
                connection.execute(
                    sa.select(ingest_text_operations)
                    .where(
                        ingest_text_operations.c.tenant_id == tenant_id,
                        ingest_text_operations.c.source_id == source_id,
                    )
                    .order_by(
                        ingest_text_operations.c.created_at.desc(),
                        ingest_text_operations.c.idempotency_key.desc(),
                    )
                    .limit(1)
                )
                .mappings()
                .first()
            )
        return dict(row) if row is not None else None

    def find_by_workflow(self, workflow_id: str) -> dict[str, Any] | None:
        with self._engine.connect() as connection:
            row = (
                connection.execute(
                    sa.select(ingest_text_operations)
                    .where(ingest_text_operations.c.workflow_id == workflow_id)
                    .limit(1)
                )
                .mappings()
                .first()
            )
        return dict(row) if row is not None else None

    def claim(
        self, values: dict[str, Any], store_artifact: Callable[[], None] | None = None
    ) -> tuple[dict[str, Any], bool]:
        """Insert the operation row, returning whether this call created it.

        Raises:
            SourceBusy: When another unresolved operation already owns the
                source, so the partial unique index rejected this claim. The
                same-key conflict is not busy: it is returned as a replay.
        """
        tenant_id = values["tenant_id"]
        idempotency_key = values["idempotency_key"]
        inserted = False
        with self._engine.begin() as connection:
            self._lock_source(connection, tenant_id, values["source_id"])
            existing = (
                connection.execute(
                    sa.select(ingest_text_operations).where(
                        ingest_text_operations.c.tenant_id == tenant_id,
                        ingest_text_operations.c.idempotency_key == idempotency_key,
                    )
                )
                .mappings()
                .first()
            )
            if existing is not None:
                return dict(existing), False
            busy = connection.execute(
                sa.select(ingest_text_operations.c.idempotency_key).where(
                    ingest_text_operations.c.tenant_id == tenant_id,
                    ingest_text_operations.c.source_id == values["source_id"],
                    ingest_text_operations.c.lifecycle_status.in_(_ACTIVE_STATUSES),
                )
            ).first()
            if busy is not None:
                raise SourceBusy()
            # All undeleted revisions of a source must share the cleanup target.
            # Moving a source to a different collection requires verified delete
            # first; otherwise deleting only the latest revision leaks old points.
            previous = connection.execute(
                sa.select(ingest_text_operations.c.request_metadata).where(
                    ingest_text_operations.c.tenant_id == tenant_id,
                    ingest_text_operations.c.source_id == values["source_id"],
                    ingest_text_operations.c.lifecycle_status != STATUS_DELETED,
                )
            ).scalars()
            target = (
                values["request_metadata"]
                .get("workflow_input", {})
                .get("ingestion", {})
                .get("collection")
            )
            if any(
                item.get("workflow_input", {}).get("ingestion", {}).get("collection")
                != target
                for item in previous
            ):
                raise SourceBusy()
            previous_rows = self._source_rows(
                connection, tenant_id, values["source_id"]
            )
            for previous_row in previous_rows:
                if previous_row["lifecycle_status"] != STATUS_DELETED:
                    if not writers_released(previous_row["request_metadata"]):
                        raise SourceBusy()
                    metadata = dict(previous_row["request_metadata"])
                    metadata["managed_writer_gate"] = "closed"
                    connection.execute(
                        ingest_text_operations.update()
                        .where(
                            ingest_text_operations.c.tenant_id == tenant_id,
                            ingest_text_operations.c.idempotency_key
                            == previous_row["idempotency_key"],
                        )
                        .values(request_metadata=metadata)
                    )
            if store_artifact is not None:
                store_artifact()
            try:
                with connection.begin_nested():
                    connection.execute(ingest_text_operations.insert().values(**values))
                inserted = True
            except IntegrityError:
                pass
            row = (
                connection.execute(
                    sa.select(ingest_text_operations)
                    .where(
                        ingest_text_operations.c.tenant_id == tenant_id,
                        ingest_text_operations.c.idempotency_key == idempotency_key,
                    )
                    .limit(1)
                )
                .mappings()
                .first()
            )
            if row is None:
                raise SourceBusy() from None
        return dict(row), inserted

    def record_dispatch(
        self,
        tenant_id: str,
        idempotency_key: str,
        run_id: str | None,
    ) -> None:
        with self._engine.begin() as connection:
            connection.execute(
                ingest_text_operations.update()
                .where(
                    ingest_text_operations.c.tenant_id == tenant_id,
                    ingest_text_operations.c.idempotency_key == idempotency_key,
                    ingest_text_operations.c.lifecycle_status.in_(
                        [STATUS_ACCEPTED, STATUS_STARTED]
                    ),
                )
                .values(
                    run_id=run_id,
                    lifecycle_status=STATUS_STARTED,
                    updated_at=sa.func.now(),
                )
            )

    def complete(
        self,
        *,
        event_id: str,
        payload_hash: str,
        workflow_id: str,
        source_id: str,
        status: str,
        run_id: str | None,
    ) -> tuple[dict[str, Any], bool, bool]:
        """Record one signed worker event and project the terminal state.

        Returns:
            The operation row, whether the event was already recorded, and
            whether the event was ignored because the source is already being
            deleted or has reached a newer state.

        Raises:
            OperationNotFound: When no owned operation matches the event.
            EventCollision: When the event id was reused with another body.
        """
        with self._engine.begin() as connection:
            row = (
                connection.execute(
                    sa.select(ingest_text_operations)
                    .where(ingest_text_operations.c.workflow_id == workflow_id)
                    .limit(1)
                )
                .mappings()
                .first()
            )
            if row is None or row["source_id"] != source_id:
                raise OperationNotFound()

            duplicate = False
            try:
                with connection.begin_nested():
                    connection.execute(
                        ingest_text_events.insert().values(
                            event_id=event_id,
                            tenant_id=row["tenant_id"],
                            operation_workflow_id=workflow_id,
                            source_id=source_id,
                            payload_hash=payload_hash,
                            status=status,
                        )
                    )
            except IntegrityError:
                stored = connection.execute(
                    sa.select(ingest_text_events.c.payload_hash).where(
                        ingest_text_events.c.event_id == event_id
                    )
                ).scalar_one()
                if stored != payload_hash:
                    raise EventCollision() from None
                duplicate = True

            ignored = row["lifecycle_status"] in {
                STATUS_DELETING,
                STATUS_DELETE_FAILED,
                STATUS_DELETED,
            }

            if (
                not duplicate
                and not ignored
                and status in {STATUS_READY, STATUS_FAILED}
            ):
                allowed = [STATUS_ACCEPTED, STATUS_STARTED, status]
                connection.execute(
                    ingest_text_operations.update()
                    .where(
                        ingest_text_operations.c.tenant_id == row["tenant_id"],
                        ingest_text_operations.c.idempotency_key
                        == row["idempotency_key"],
                        ingest_text_operations.c.lifecycle_status.in_(allowed),
                    )
                    .values(
                        lifecycle_status=status,
                        run_id=run_id or row["run_id"],
                        updated_at=sa.func.now(),
                    )
                )

            updated = (
                connection.execute(
                    sa.select(ingest_text_operations)
                    .where(
                        ingest_text_operations.c.tenant_id == row["tenant_id"],
                        ingest_text_operations.c.idempotency_key
                        == row["idempotency_key"],
                    )
                    .limit(1)
                )
                .mappings()
                .one()
            )
        return dict(updated), duplicate, ignored

    def prepare_delete(
        self,
        *,
        tenant_id: str,
        source_id: str,
        deletion_key: str,
        dataset_id: str | None = None,
    ) -> tuple[DeletionSnapshot, bool, bool]:
        """Mark one owned source as deleting and return its snapshot.

        Returns:
            The immutable deletion snapshot, whether deletion is complete, and
            whether the workflow is known to be closed. Completed deletion
            replays its receipt. In-progress cleanup is refused even for the
            same key; failed cleanup may be retried after its owner exits.

        Raises:
            OperationNotFound: When no owned operation matches the source.
            SourceBusy: When the workflow start is still unconfirmed or a
                different deletion owns the source.
        """
        with self._engine.begin() as connection:
            self._lock_source(connection, tenant_id, source_id)
            previous = (
                connection.execute(
                    sa.select(ingest_text_operations).where(
                        ingest_text_operations.c.tenant_id == tenant_id,
                        ingest_text_operations.c.source_id == source_id,
                        ingest_text_operations.c.deletion_key
                        == _deletion_digest(deletion_key),
                        ingest_text_operations.c.lifecycle_status == STATUS_DELETED,
                    )
                )
                .mappings()
                .first()
            )
            if previous is not None:
                if dataset_id is not None and previous["dataset_id"] != dataset_id:
                    raise SourceBusy()
                return self._deletion_snapshot(dict(previous), ()), True, True
            row = (
                connection.execute(
                    sa.select(ingest_text_operations)
                    .where(
                        ingest_text_operations.c.tenant_id == tenant_id,
                        ingest_text_operations.c.source_id == source_id,
                    )
                    .order_by(
                        ingest_text_operations.c.created_at.desc(),
                        ingest_text_operations.c.idempotency_key.desc(),
                    )
                    .limit(1)
                    .with_for_update()
                )
                .mappings()
                .first()
            )
            if row is None:
                raise OperationNotFound()

            if dataset_id is not None and row["dataset_id"] != dataset_id:
                raise SourceBusy()

            status = row["lifecycle_status"]
            if status == STATUS_DELETED:
                return self._deletion_snapshot(dict(row), ()), True, True
            if status == STATUS_ACCEPTED:
                raise SourceBusy()
            if status == STATUS_DELETING:
                # Never run overlapping cleanup, even for the same key. A
                # delayed cleanup could otherwise erase a new ingestion.
                raise SourceBusy()
            if status in {STATUS_STARTED, STATUS_READY, STATUS_FAILED}:
                workflow_closed = status == STATUS_READY
            elif status == STATUS_DELETE_FAILED:
                workflow_closed = False
            else:  # pragma: no cover - protected by the table check constraint
                raise SourceBusy()

            owners = self._source_rows(connection, tenant_id, source_id)
            owners = [
                owner for owner in owners if owner["lifecycle_status"] != STATUS_DELETED
            ]
            # Gate closure and ownership selection share the exact admission lock.
            # Every revision is fenced; a delayed activity on an old ready
            # operation must never reacquire permission on this source.
            fence_id = uuid.uuid4().hex
            for owner in owners:
                metadata = dict(owner["request_metadata"])
                metadata["managed_writer_gate"] = "closed"
                metadata["managed_deletion"] = {"operation_id": fence_id}
                connection.execute(
                    ingest_text_operations.update()
                    .where(
                        ingest_text_operations.c.tenant_id == tenant_id,
                        ingest_text_operations.c.idempotency_key
                        == owner["idempotency_key"],
                    )
                    .values(request_metadata=metadata)
                )
            connection.execute(
                ingest_text_operations.update()
                .where(
                    ingest_text_operations.c.tenant_id == tenant_id,
                    ingest_text_operations.c.idempotency_key == row["idempotency_key"],
                )
                .values(
                    lifecycle_status=STATUS_DELETING,
                    deletion_key=_deletion_digest(deletion_key),
                    updated_at=sa.func.now(),
                )
            )
            updated = (
                connection.execute(
                    sa.select(ingest_text_operations)
                    .where(
                        ingest_text_operations.c.tenant_id == tenant_id,
                        ingest_text_operations.c.idempotency_key
                        == row["idempotency_key"],
                    )
                    .limit(1)
                )
                .mappings()
                .one()
            )
            snapshot = self._deletion_snapshot(
                dict(updated),
                tuple(
                    (owner["idempotency_key"], owner_identity(owner))
                    for owner in owners
                ),
            )
        return snapshot, False, workflow_closed

    @staticmethod
    def _source_rows(
        connection: sa.Connection, tenant_id: str, source_id: str
    ) -> list[dict[str, Any]]:
        return [
            dict(row)
            for row in connection.execute(
                sa.select(ingest_text_operations)
                .where(
                    ingest_text_operations.c.tenant_id == tenant_id,
                    ingest_text_operations.c.source_id == source_id,
                )
                .order_by(
                    ingest_text_operations.c.created_at.desc(),
                    ingest_text_operations.c.idempotency_key.desc(),
                )
                .with_for_update()
            ).mappings()
        ]

    @staticmethod
    def _deletion_snapshot(
        row: dict[str, Any], owners: tuple[tuple[str, str], ...]
    ) -> DeletionSnapshot:
        metadata = row["request_metadata"]
        collection = (
            metadata.get("workflow_input", {}).get("ingestion", {}).get("collection")
        )
        if not isinstance(collection, str) or not collection:
            raise SourceBusy()
        return DeletionSnapshot(
            tenant_id=row["tenant_id"],
            dataset_id=row["dataset_id"],
            source_id=row["source_id"],
            document_id=document_id(row["source_id"]),
            idempotency_key=row["idempotency_key"],
            workflow_id=row["workflow_id"],
            run_id=row["run_id"],
            collection=collection,
            markdown_path=row["markdown_path"],
            deletion_key=row["deletion_key"] or "",
            fence_id=metadata.get("managed_deletion", {}).get("operation_id", ""),
            owners=owners,
        )

    def record_writer(self, event: WriterEvent) -> dict[str, Any]:
        row = self.find_by_workflow(event.workflow_id)
        if row is None:
            raise WriterConflict()
        with self._engine.begin() as connection:
            self._lock_source(connection, row["tenant_id"], row["source_id"])
            rows = self._source_rows(connection, row["tenant_id"], row["source_id"])
            row = next(
                item for item in rows if item["workflow_id"] == event.workflow_id
            )
            metadata, receipt = update_writer(
                row, event, current_owner=rows[0]["workflow_id"] == event.workflow_id
            )
            connection.execute(
                ingest_text_operations.update()
                .where(
                    ingest_text_operations.c.tenant_id == row["tenant_id"],
                    ingest_text_operations.c.idempotency_key == row["idempotency_key"],
                )
                .values(request_metadata=metadata, run_id=row["run_id"] or event.run_id)
            )
        return receipt

    def _check_snapshot(
        self, connection: sa.Connection, snapshot: DeletionSnapshot, *, drained: bool
    ) -> list[dict[str, Any]]:
        self._lock_source(connection, snapshot.tenant_id, snapshot.source_id)
        rows = [
            row
            for row in self._source_rows(
                connection, snapshot.tenant_id, snapshot.source_id
            )
            if row["lifecycle_status"] != STATUS_DELETED
        ]
        if (
            not rows
            or rows[0]["idempotency_key"] != snapshot.idempotency_key
            or rows[0]["lifecycle_status"] != STATUS_DELETING
            or rows[0]["deletion_key"] != snapshot.deletion_key
            or tuple((row["idempotency_key"], owner_identity(row)) for row in rows)
            != snapshot.owners
            or any(
                row["request_metadata"].get("managed_deletion", {}).get("operation_id")
                != snapshot.fence_id
                or row["request_metadata"].get("managed_writer_gate") != "closed"
                for row in rows
            )
            or (
                drained
                and any(not writers_released(row["request_metadata"]) for row in rows)
            )
        ):
            raise SourceBusy()
        return rows

    def require_drained(self, snapshot: DeletionSnapshot) -> None:
        with self._engine.begin() as connection:
            self._check_snapshot(connection, snapshot, drained=True)

    def finish_delete(
        self, snapshot: DeletionSnapshot, remove_artifacts: Callable[[], None]
    ) -> None:
        # The filesystem action is under the source lock, like artifact creation
        # in claim(). No network call holds this transaction open.
        with self._engine.begin() as connection:
            rows = self._check_snapshot(connection, snapshot, drained=True)
            remove_artifacts()
            connection.execute(
                ingest_text_operations.update()
                .where(
                    ingest_text_operations.c.tenant_id == snapshot.tenant_id,
                    ingest_text_operations.c.idempotency_key.in_(
                        [row["idempotency_key"] for row in rows]
                    ),
                )
                .values(
                    lifecycle_status=STATUS_DELETED,
                    deleted_at=_now(),
                    updated_at=sa.func.now(),
                )
            )

    def fail_delete(self, snapshot: DeletionSnapshot) -> None:
        with self._engine.begin() as connection:
            try:
                self._check_snapshot(connection, snapshot, drained=False)
            except SourceBusy:
                return  # A stale cleanup must never mutate its successor.
            connection.execute(
                ingest_text_operations.update()
                .where(
                    ingest_text_operations.c.tenant_id == snapshot.tenant_id,
                    ingest_text_operations.c.idempotency_key
                    == snapshot.idempotency_key,
                )
                .values(lifecycle_status=STATUS_DELETE_FAILED, updated_at=sa.func.now())
            )

    @staticmethod
    def _lock_source(connection: sa.Connection, tenant_id: str, source_id: str) -> None:
        # Serialize claim vs deletion even when no operation row exists yet.
        # Released at transaction end, before any network operation.
        if connection.dialect.name == "postgresql":
            key = int.from_bytes(
                hashlib.sha256((tenant_id + "|" + source_id).encode()).digest()[:8],
                "big",
                signed=True,
            )
            connection.execute(
                sa.text("SELECT pg_advisory_xact_lock(:key)"), {"key": key}
            )


__all__ = [
    "EventCollision",
    "OperationConflict",
    "OperationNotFound",
    "SourceBusy",
    "TextOperationRepository",
]
