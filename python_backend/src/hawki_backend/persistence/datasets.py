"""Durable, tenant-scoped dataset authority.

The store is the only writer of the canonical dataset metadata, grants and
one-time grant tokens. Every method scopes its statements by tenant. The
tenant is never inferred from a dataset id and a dataset id is never resolved
across tenants.

A database failure is never a refusal. Every public method raises
`DatasetStoreUnavailableError` when the store cannot answer, so an outage can
never be reported as "not found" and can never turn into an accidental allow.

Grant tokens are generated here, hashed with SHA-256 and persisted by hash
only. The plaintext is returned once to the caller and is never stored or
logged. Redemption locks the token row, marks it consumed and inserts the
grant in one transaction, so exactly one concurrent redeemer wins.
"""

from __future__ import annotations

import hashlib
import logging
import re
import secrets
from collections.abc import Mapping
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone

import sqlalchemy as sa
from sqlalchemy import select
from sqlalchemy.dialects.postgresql import insert as pg_insert
from sqlalchemy.engine import Connection, Engine
from sqlalchemy.exc import DBAPIError, IntegrityError
from sqlalchemy.exc import TimeoutError as PoolTimeoutError

from hawki_backend.auth.models import AuthenticatedPrincipal
from hawki_backend.authorization.errors import AuthorizationUnavailableError
from hawki_backend.authorization.models import AuthorizationAction
from hawki_backend.persistence.schema import (
    ACTIVE_STATUS,
    ARCHIVED_STATUS,
    DELETED_STATUS,
    dataset_grant_tokens,
    dataset_grants,
    datasets,
)
from hawki_backend.query.settings import DatasetScope

_logger = logging.getLogger(__name__)

TOKEN_PREFIX = "dgt_"
TOKEN_TTL = timedelta(hours=24)
_TOKEN_HASH_LENGTH = 64
_DATASET_ID_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$")


class DatasetStoreUnavailableError(Exception):
    """The canonical dataset store could not be reached or read."""


class DatasetNotFoundError(Exception):
    """No dataset with this identity exists in this tenant."""


class DatasetInactiveError(Exception):
    """The dataset exists but is not active."""


class DatasetTombstoneError(Exception):
    """The dataset identity is tombstoned and must not be revived."""


class DatasetConflictError(Exception):
    """An existing dataset does not match the requested metadata."""

    def __init__(self, dataset_id: str, field: str) -> None:
        super().__init__(
            f"Dataset [{dataset_id}] already exists with a different [{field}]."
        )
        self.dataset_id = dataset_id
        self.field = field


class GrantTokenInvalidError(Exception):
    """A grant token is unknown, expired, consumed or bound to another dataset."""


@dataclass(frozen=True, slots=True)
class DatasetRecord:
    """One canonical dataset row."""

    tenant_id: str
    dataset_id: str
    name: str
    description: str | None
    status: str
    qdrant_collection: str
    neo4j_namespace: str | None
    embedding_provider: str
    embedding_model: str
    graph_enabled: bool
    deleted_at: datetime | None
    created_at: datetime
    updated_at: datetime

    def is_scope_complete(self) -> bool:
        return bool(
            self.qdrant_collection and self.embedding_provider and self.embedding_model
        )

    def scope(self) -> DatasetScope | None:
        if self.status != ACTIVE_STATUS or not self.is_scope_complete():
            return None
        return DatasetScope(
            qdrant_collection=self.qdrant_collection,
            embedding_provider=self.embedding_provider,
            embedding_model=self.embedding_model,
            neo4j_namespace=self.neo4j_namespace,
            graph_enabled=self.graph_enabled,
        )


_DATASET_COLUMNS = (
    datasets.c.tenant_id,
    datasets.c.dataset_id,
    datasets.c.name,
    datasets.c.description,
    datasets.c.status,
    datasets.c.qdrant_collection,
    datasets.c.neo4j_namespace,
    datasets.c.embedding_provider,
    datasets.c.embedding_model,
    datasets.c.graph_enabled,
    datasets.c.deleted_at,
    datasets.c.created_at,
    datasets.c.updated_at,
)


class DatasetStore:
    """Read and write canonical dataset state in the owned schema."""

    def __init__(self, engine: Engine) -> None:
        self._engine = engine

    def get(self, tenant_id: str, dataset_id: str) -> DatasetRecord | None:
        """Return one dataset of this tenant, whatever its lifecycle state."""

        statement = select(*_DATASET_COLUMNS).where(
            datasets.c.tenant_id == sa.bindparam("tenant_id", value=tenant_id),
            datasets.c.dataset_id == sa.bindparam("dataset_id", value=dataset_id),
        )
        try:
            with self._engine.connect() as connection:
                row = connection.execute(statement).mappings().first()
        except _STORE_ERRORS as error:
            _log_unavailable(error)
            raise DatasetStoreUnavailableError() from None
        return _record(row) if row is not None else None

    def list_archived(self, limit: int = 100) -> list[DatasetRecord]:
        """Return archived datasets across tenants as durable cleanup work."""

        statement = (
            select(*_DATASET_COLUMNS)
            .where(datasets.c.status == sa.bindparam("status", value=ARCHIVED_STATUS))
            .order_by(datasets.c.updated_at, datasets.c.dataset_id)
            .limit(sa.bindparam("limit", value=max(1, min(1000, limit))))
        )
        try:
            with self._engine.connect() as connection:
                rows = connection.execute(statement).mappings().all()
        except _STORE_ERRORS as error:
            _log_unavailable(error)
            raise DatasetStoreUnavailableError() from None
        return [_record(row) for row in rows]

    def list_active(self, tenant_id: str, limit: int) -> list[DatasetRecord]:
        """Return the tenant's active datasets, newest first."""

        statement = (
            select(*_DATASET_COLUMNS)
            .where(
                datasets.c.tenant_id == sa.bindparam("tenant_id", value=tenant_id),
                datasets.c.status == sa.bindparam("status", value=ACTIVE_STATUS),
            )
            .order_by(datasets.c.created_at.desc(), datasets.c.dataset_id.desc())
            .limit(sa.bindparam("limit", value=max(1, min(250, limit))))
        )
        try:
            with self._engine.connect() as connection:
                rows = connection.execute(statement).mappings().all()
        except _STORE_ERRORS as error:
            _log_unavailable(error)
            raise DatasetStoreUnavailableError() from None
        return [_record(row) for row in rows]

    def create(
        self,
        tenant_id: str,
        *,
        dataset_id: str,
        name: str,
        description: str | None,
        qdrant_collection: str,
        neo4j_namespace: str | None,
        embedding_provider: str,
        embedding_model: str,
        graph_enabled: bool,
        requested: Mapping[str, object],
        issue_token: bool = True,
        now: datetime | None = None,
    ) -> tuple[DatasetRecord, bool, str | None]:
        """Create one dataset and, on first creation, its bootstrap token.

        A compatible replay returns the existing active row and never issues a
        second token. An archived or tombstoned identity is never revived and
        always conflicts.

        Returns:
            The row, whether it was created by this call, and the one-time
            plaintext token when it was created.
        """

        if not _DATASET_ID_PATTERN.fullmatch(dataset_id):
            raise DatasetConflictError(dataset_id, "dataset_id")
        stamped = _utc(now)
        token_plaintext: str | None = None
        try:
            with self._engine.begin() as connection:
                inserted = (
                    connection.execute(
                        pg_insert(datasets)
                        .values(
                            tenant_id=tenant_id,
                            dataset_id=dataset_id,
                            name=name,
                            description=description,
                            status=ACTIVE_STATUS,
                            qdrant_collection=qdrant_collection,
                            neo4j_namespace=neo4j_namespace,
                            embedding_provider=embedding_provider,
                            embedding_model=embedding_model,
                            graph_enabled=graph_enabled,
                            created_at=stamped,
                            updated_at=stamped,
                        )
                        .on_conflict_do_nothing(
                            index_elements=[datasets.c.tenant_id, datasets.c.dataset_id]
                        )
                        .returning(*_DATASET_COLUMNS)
                    )
                    .mappings()
                    .first()
                )
                created = inserted is not None
                if inserted is None:
                    existing = (
                        connection.execute(
                            select(*_DATASET_COLUMNS)
                            .where(
                                datasets.c.tenant_id == tenant_id,
                                datasets.c.dataset_id == dataset_id,
                            )
                            .with_for_update()
                        )
                        .mappings()
                        .first()
                    )
                    if existing is None:
                        raise DatasetStoreUnavailableError()
                    record = _record(existing)
                    if record.status == DELETED_STATUS:
                        raise DatasetTombstoneError()
                    if record.status != ACTIVE_STATUS:
                        raise DatasetInactiveError()
                    for field, expected in requested.items():
                        if expected is not None and getattr(record, field) != expected:
                            raise DatasetConflictError(dataset_id, field)
                    return record, False, None

                record = _record(inserted)
                if issue_token:
                    token_plaintext = _insert_token(
                        connection,
                        tenant_id,
                        dataset_id,
                        stamped,
                    )
                return record, created, token_plaintext
        except (DatasetConflictError, DatasetInactiveError, DatasetTombstoneError):
            raise
        except IntegrityError:
            # A unique physical target collided with another dataset. Never
            # echo the conflicting value or the driver message.
            raise DatasetConflictError(dataset_id, "physical_scope") from None
        except _STORE_ERRORS as error:
            _log_unavailable(error)
            raise DatasetStoreUnavailableError() from None

    def archive(
        self,
        tenant_id: str,
        dataset_id: str,
        *,
        now: datetime | None = None,
    ) -> DatasetRecord | None:
        """Move an active dataset to archived; a deleted row is unchanged."""

        stamped = _utc(now)
        try:
            with self._engine.begin() as connection:
                row = (
                    connection.execute(
                        select(*_DATASET_COLUMNS)
                        .where(
                            datasets.c.tenant_id == tenant_id,
                            datasets.c.dataset_id == dataset_id,
                        )
                        .with_for_update()
                    )
                    .mappings()
                    .first()
                )
                if row is None:
                    return None
                record = _record(row)
                if record.status == DELETED_STATUS:
                    return record
                updated = (
                    connection.execute(
                        datasets.update()
                        .where(
                            datasets.c.tenant_id == tenant_id,
                            datasets.c.dataset_id == dataset_id,
                        )
                        .values(status=ARCHIVED_STATUS, updated_at=stamped)
                        .returning(*_DATASET_COLUMNS)
                    )
                    .mappings()
                    .first()
                )
                return _record(updated) if updated is not None else record
        except _STORE_ERRORS as error:
            _log_unavailable(error)
            raise DatasetStoreUnavailableError() from None

    def finalize_deletion(
        self,
        tenant_id: str,
        dataset_id: str,
        *,
        now: datetime | None = None,
    ) -> DatasetRecord | None:
        """Tombstone an archived dataset; an active dataset must be archived first."""

        stamped = _utc(now)
        try:
            with self._engine.begin() as connection:
                row = (
                    connection.execute(
                        select(*_DATASET_COLUMNS)
                        .where(
                            datasets.c.tenant_id == tenant_id,
                            datasets.c.dataset_id == dataset_id,
                        )
                        .with_for_update()
                    )
                    .mappings()
                    .first()
                )
                if row is None:
                    return None
                record = _record(row)
                if record.status == DELETED_STATUS:
                    return record
                if record.status != ARCHIVED_STATUS:
                    raise DatasetInactiveError()
                updated = (
                    connection.execute(
                        datasets.update()
                        .where(
                            datasets.c.tenant_id == tenant_id,
                            datasets.c.dataset_id == dataset_id,
                        )
                        .values(
                            status=DELETED_STATUS,
                            deleted_at=stamped,
                            updated_at=stamped,
                        )
                        .returning(*_DATASET_COLUMNS)
                    )
                    .mappings()
                    .first()
                )
                return _record(updated) if updated is not None else record
        except DatasetInactiveError:
            raise
        except _STORE_ERRORS as error:
            _log_unavailable(error)
            raise DatasetStoreUnavailableError() from None

    def resolve_scope(
        self,
        tenant_id: str,
        dataset_id: str,
    ) -> DatasetScope | None:
        """Return trusted physical scope for an active, complete dataset."""

        record = self.get(tenant_id, dataset_id)
        return record.scope() if record is not None else None

    def upsert_grant(
        self,
        tenant_id: str,
        dataset_id: str,
        principal_type: str,
        principal_id: str,
        permission: str,
    ) -> None:
        """Insert one exact grant idempotently; never widen an existing one."""

        if permission not in {action.value for action in AuthorizationAction}:
            raise ValueError("permission must be query or ingest")
        try:
            with self._engine.begin() as connection:
                # The dataset row is the single admission fence. Archive and
                # finalization take the same lock, so a grant can never be
                # written after the dataset became inactive.
                lock_active_dataset(connection, tenant_id, dataset_id)
                connection.execute(
                    pg_insert(dataset_grants)
                    .values(
                        tenant_id=tenant_id,
                        dataset_id=dataset_id,
                        principal_type=principal_type,
                        principal_id=principal_id,
                        permission=permission,
                    )
                    .on_conflict_do_nothing(
                        index_elements=[
                            dataset_grants.c.tenant_id,
                            dataset_grants.c.dataset_id,
                            dataset_grants.c.principal_type,
                            dataset_grants.c.principal_id,
                            dataset_grants.c.permission,
                        ]
                    )
                )
        except _STORE_ERRORS as error:
            _log_unavailable(error)
            raise DatasetStoreUnavailableError() from None

    def has_grant(
        self,
        principal: AuthenticatedPrincipal,
        dataset_id: str,
        action: AuthorizationAction,
    ) -> bool:
        """Return whether one exact grant exists on an active dataset."""

        statement = (
            select(sa.literal_column("1"))
            .select_from(
                dataset_grants.join(
                    datasets,
                    (datasets.c.tenant_id == dataset_grants.c.tenant_id)
                    & (datasets.c.dataset_id == dataset_grants.c.dataset_id),
                )
            )
            .where(
                dataset_grants.c.tenant_id == principal.tenant_id,
                dataset_grants.c.principal_type == principal.kind.value,
                dataset_grants.c.principal_id == principal.principal_id,
                dataset_grants.c.dataset_id == dataset_id,
                dataset_grants.c.permission == action.value,
                datasets.c.status == ACTIVE_STATUS,
            )
            .limit(1)
        )
        try:
            with self._engine.connect() as connection:
                return connection.execute(statement).first() is not None
        except _STORE_ERRORS as error:
            _log_unavailable(error)
            raise DatasetStoreUnavailableError() from None

    def list_query_datasets(
        self,
        principal: AuthenticatedPrincipal,
    ) -> list[DatasetRecord]:
        """Return active datasets with an exact query grant and complete scope."""

        statement = (
            select(*_DATASET_COLUMNS)
            .select_from(
                datasets.join(
                    dataset_grants,
                    (datasets.c.tenant_id == dataset_grants.c.tenant_id)
                    & (datasets.c.dataset_id == dataset_grants.c.dataset_id),
                )
            )
            .where(
                datasets.c.tenant_id == principal.tenant_id,
                datasets.c.status == ACTIVE_STATUS,
                dataset_grants.c.principal_type == principal.kind.value,
                dataset_grants.c.principal_id == principal.principal_id,
                dataset_grants.c.permission == AuthorizationAction.QUERY.value,
                datasets.c.qdrant_collection != "",
                datasets.c.embedding_provider != "",
                datasets.c.embedding_model != "",
            )
            .order_by(datasets.c.name, datasets.c.dataset_id)
        )
        try:
            with self._engine.connect() as connection:
                rows = connection.execute(statement).mappings().all()
        except _STORE_ERRORS as error:
            _log_unavailable(error)
            raise DatasetStoreUnavailableError() from None
        return [_record(row) for row in rows]

    def owned_source_counts(self, tenant_id: str, dataset_id: str) -> dict[str, int]:
        """Count Python-owned source rows that still block final deletion.

        The per-source deletion protocols are the only way those rows reach
        ``deleted``. The dataset lifecycle never bypasses them.
        """

        from hawki_backend.managed_documents.schema import documents
        from hawki_backend.text_ingestion.schema import ingest_text_operations
        from hawki_backend.url_ingestion.schema import sources

        statements = {
            "managed_documents": select(sa.func.count())
            .select_from(documents)
            .where(
                documents.c.tenant_id == tenant_id,
                documents.c.dataset_id == dataset_id,
                documents.c.status != "deleted",
            ),
            "text_ingestions": select(sa.func.count())
            .select_from(ingest_text_operations)
            .where(
                ingest_text_operations.c.tenant_id == tenant_id,
                ingest_text_operations.c.dataset_id == dataset_id,
                ingest_text_operations.c.lifecycle_status != "deleted",
            ),
            "url_sources": select(sa.func.count())
            .select_from(sources)
            .where(
                sources.c.tenant_id == tenant_id,
                sources.c.dataset_id == dataset_id,
                sources.c.status != "deleted",
            ),
        }
        try:
            with self._engine.connect() as connection:
                return {
                    name: int(connection.execute(statement).scalar_one())
                    for name, statement in statements.items()
                }
        except _STORE_ERRORS as error:
            _log_unavailable(error)
            raise DatasetStoreUnavailableError() from None

    def issue_grant_token(
        self,
        tenant_id: str,
        dataset_id: str,
        *,
        now: datetime | None = None,
    ) -> tuple[str, datetime]:
        """Issue one bootstrap token; only its SHA-256 hash is persisted."""

        stamped = _utc(now)
        try:
            with self._engine.begin() as connection:
                plaintext = _insert_token(connection, tenant_id, dataset_id, stamped)
        except _STORE_ERRORS as error:
            _log_unavailable(error)
            raise DatasetStoreUnavailableError() from None
        return plaintext, stamped + TOKEN_TTL

    def redeem_grant_token(
        self,
        tenant_id: str,
        dataset_id: str,
        principal_type: str,
        principal_id: str,
        plaintext: str,
        *,
        now: datetime | None = None,
    ) -> None:
        """Consume one token and create the exact ingest grant atomically."""

        stamped = _utc(now)
        token_hash = _token_hash(plaintext)
        try:
            with self._engine.begin() as connection:
                # Redemption is an admission decision too: it must not commit
                # after the dataset was archived, and it holds the same row
                # lock as archive/finalization.
                lock_active_dataset(connection, tenant_id, dataset_id)
                row = (
                    connection.execute(
                        select(
                            dataset_grant_tokens.c.tenant_id,
                            dataset_grant_tokens.c.dataset_id,
                            dataset_grant_tokens.c.expires_at,
                            dataset_grant_tokens.c.consumed_at,
                        )
                        .where(dataset_grant_tokens.c.token_hash == token_hash)
                        .with_for_update()
                    )
                    .mappings()
                    .first()
                )
                if (
                    row is None
                    or row["tenant_id"] != tenant_id
                    or row["dataset_id"] != dataset_id
                    or row["consumed_at"] is not None
                    or stamped > row["expires_at"]
                ):
                    raise GrantTokenInvalidError()
                connection.execute(
                    dataset_grant_tokens.update()
                    .where(dataset_grant_tokens.c.token_hash == token_hash)
                    .values(consumed_at=stamped, updated_at=stamped)
                )
                connection.execute(
                    pg_insert(dataset_grants)
                    .values(
                        tenant_id=tenant_id,
                        dataset_id=dataset_id,
                        principal_type=principal_type,
                        principal_id=principal_id,
                        permission=AuthorizationAction.INGEST.value,
                    )
                    .on_conflict_do_nothing(
                        index_elements=[
                            dataset_grants.c.tenant_id,
                            dataset_grants.c.dataset_id,
                            dataset_grants.c.principal_type,
                            dataset_grants.c.principal_id,
                            dataset_grants.c.permission,
                        ]
                    )
                )
        except GrantTokenInvalidError:
            raise
        except _STORE_ERRORS as error:
            _log_unavailable(error)
            raise DatasetStoreUnavailableError() from None


_STORE_ERRORS = (DBAPIError, PoolTimeoutError)


def lock_active_dataset(
    connection: Connection,
    tenant_id: str,
    dataset_id: str,
) -> DatasetRecord:
    """Lock one dataset row and require it to be active.

    This is the single PostgreSQL admission fence shared by text, managed and
    URL admission, grant writes, token redemption, archive and finalization.
    Holding the same row lock in every writer transaction means a dataset can
    never be archived while an admission is still committing, and an admission
    can never commit after the archive committed.

    Raises:
        DatasetNotFoundError: When the tenant has no such dataset.
        DatasetInactiveError: When the dataset is archived or tombstoned.
    """

    statement = (
        select(*_DATASET_COLUMNS)
        .where(
            datasets.c.tenant_id == sa.bindparam("tenant_id", value=tenant_id),
            datasets.c.dataset_id == sa.bindparam("dataset_id", value=dataset_id),
        )
        .with_for_update()
    )
    row = connection.execute(statement).mappings().first()
    if row is None:
        raise DatasetNotFoundError()
    record = _record(row)
    if record.status != ACTIVE_STATUS:
        raise DatasetInactiveError()
    return record


def lock_dataset_for_cleanup(
    connection: Connection,
    tenant_id: str,
    dataset_id: str,
) -> DatasetRecord:
    """Lock a dataset row that is archived or active for internal cleanup.

    Cleanup runs through the same row lock as archive/finalization. A
    tombstoned row is returned unchanged so cleanup can finish idempotently.
    """

    statement = (
        select(*_DATASET_COLUMNS)
        .where(
            datasets.c.tenant_id == sa.bindparam("tenant_id", value=tenant_id),
            datasets.c.dataset_id == sa.bindparam("dataset_id", value=dataset_id),
        )
        .with_for_update()
    )
    row = connection.execute(statement).mappings().first()
    if row is None:
        raise DatasetNotFoundError()
    return _record(row)


class DatabaseDatasetScopeRegistry:
    """Resolve trusted physical scope from the canonical dataset store.

    The registry is the runtime adapter between the query configuration's
    resolver protocol and the database-backed dataset authority. A dataset
    that is absent, inactive or incomplete has no scope. A store that cannot
    be reached is an authorization infrastructure failure, never a denial.
    """

    def __init__(self, store: DatasetStore) -> None:
        self._store = store

    def resolve_scope(
        self,
        dataset_id: str,
        tenant_id: str | None = None,
    ) -> DatasetScope | None:
        """Return the active, complete scope of this tenant's dataset."""

        if tenant_id is None:
            # The canonical store is tenant-scoped. Without the authenticated
            # tenant there is no safe scope to resolve and no denial to report.
            raise AuthorizationUnavailableError()
        try:
            return self._store.resolve_scope(tenant_id, dataset_id)
        except DatasetStoreUnavailableError:
            raise AuthorizationUnavailableError() from None


def _record(row: Mapping[str, object]) -> DatasetRecord:
    return DatasetRecord(
        tenant_id=str(row["tenant_id"]),
        dataset_id=str(row["dataset_id"]),
        name=str(row["name"]),
        description=row["description"] if row["description"] is not None else None,
        status=str(row["status"]),
        qdrant_collection=str(row["qdrant_collection"]),
        neo4j_namespace=(
            str(row["neo4j_namespace"]) if row["neo4j_namespace"] is not None else None
        ),
        embedding_provider=str(row["embedding_provider"]),
        embedding_model=str(row["embedding_model"]),
        graph_enabled=bool(row["graph_enabled"]),
        deleted_at=row["deleted_at"] if row["deleted_at"] is not None else None,
        created_at=row["created_at"],
        updated_at=row["updated_at"],
    )


def _insert_token(
    connection: sa.Connection,
    tenant_id: str,
    dataset_id: str,
    now: datetime,
) -> str:
    """Insert a fresh token and return the plaintext exactly once."""

    plaintext = TOKEN_PREFIX + secrets.token_hex(32)
    connection.execute(
        dataset_grant_tokens.insert().values(
            token_hash=_token_hash(plaintext),
            tenant_id=tenant_id,
            dataset_id=dataset_id,
            expires_at=now + TOKEN_TTL,
            created_at=now,
            updated_at=now,
        )
    )
    return plaintext


def _token_hash(plaintext: str) -> str:
    return hashlib.sha256(plaintext.strip().encode("utf-8")).hexdigest()


def _utc(value: datetime | None) -> datetime:
    if value is None:
        return datetime.now(timezone.utc)
    if value.tzinfo is None or value.utcoffset() is None:
        raise ValueError("now must be timezone-aware")
    return value.astimezone(timezone.utc)


def _log_unavailable(error: Exception) -> None:
    """Record the failure class without the driver's message."""

    original = getattr(error, "orig", None)
    _logger.error(
        "the dataset store could not be reached",
        extra={
            "error_type": type(original).__name__ if original else type(error).__name__,
            "sqlstate": getattr(original, "sqlstate", None),
        },
    )


__all__ = [
    "DatasetConflictError",
    "DatabaseDatasetScopeRegistry",
    "DatasetInactiveError",
    "DatasetNotFoundError",
    "DatasetRecord",
    "DatasetStore",
    "DatasetStoreUnavailableError",
    "DatasetTombstoneError",
    "GrantTokenInvalidError",
    "TOKEN_PREFIX",
    "TOKEN_TTL",
    "lock_active_dataset",
    "lock_dataset_for_cleanup",
]
