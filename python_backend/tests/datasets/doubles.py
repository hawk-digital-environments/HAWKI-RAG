"""Test doubles for the dataset authority tests.

The real store is PostgreSQL-only. The service tests exercise its ordering,
readiness and lifecycle rules against a small in-memory implementation of the
same documented contract; the PostgreSQL tests prove the statements
themselves. The API tests use a recording service so they can assert the
HTTP contract without a database.
"""

from __future__ import annotations

import hashlib
import secrets
from dataclasses import dataclass, replace
from datetime import datetime, timezone
from types import SimpleNamespace

from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind
from hawki_backend.persistence.datasets import (
    TOKEN_PREFIX,
    TOKEN_TTL,
    DatasetConflictError,
    DatasetInactiveError,
    DatasetRecord,
    DatasetStoreUnavailableError,
    DatasetTombstoneError,
    GrantTokenInvalidError,
)

TENANT = "tenant-a"
OTHER_TENANT = "tenant-b"
USER_ID = "user-1"
NOW = datetime(2026, 11, 10, 12, 0, tzinfo=timezone.utc)


def user_principal(
    *,
    subject: str = USER_ID,
    tenant_id: str = TENANT,
    scopes: tuple[str, ...] = ("rag:query", "rag:ingest"),
) -> AuthenticatedPrincipal:
    """Return a user principal with the requested scopes."""

    return AuthenticatedPrincipal(
        kind=PrincipalKind.USER,
        subject=subject,
        tenant_id=tenant_id,
        client_id="hawki-console",
        scopes=frozenset(scopes),
        issuer="https://issuer.example",
        expires_at=datetime(2100, 1, 1, tzinfo=timezone.utc),
    )


def service_principal(
    *,
    tenant_id: str = TENANT,
    scopes: tuple[str, ...] = ("rag:datasets:manage",),
) -> AuthenticatedPrincipal:
    """Return the trusted BFF service principal."""

    return AuthenticatedPrincipal(
        kind=PrincipalKind.SERVICE,
        subject="hawki-bff",
        tenant_id=tenant_id,
        client_id="hawki-bff",
        scopes=frozenset(scopes),
        issuer="https://issuer.example",
        expires_at=datetime(2100, 1, 1, tzinfo=timezone.utc),
    )


class FakeAuthenticator:
    """Return one fixed principal for every bearer token."""

    def __init__(self, principal: AuthenticatedPrincipal | None) -> None:
        self._principal = principal

    def authenticate(self, _token: str) -> AuthenticatedPrincipal:
        if self._principal is None:
            from hawki_backend.auth.errors import AuthenticationError, AuthFailureReason

            raise AuthenticationError(AuthFailureReason.MALFORMED_TOKEN)
        return self._principal


def record(
    *,
    tenant_id: str = TENANT,
    dataset_id: str = "dataset-a",
    name: str = "Dataset A",
    status: str = "active",
    collection: str = "hawki_dataset_a",
    namespace: str | None = "hawki_dataset_a",
    provider: str = "ollama",
    model: str = "bge-m3",
    graph_enabled: bool = False,
) -> DatasetRecord:
    """Return one canonical record."""

    return DatasetRecord(
        tenant_id=tenant_id,
        dataset_id=dataset_id,
        name=name,
        description=None,
        status=status,
        qdrant_collection=collection,
        neo4j_namespace=namespace,
        embedding_provider=provider,
        embedding_model=model,
        graph_enabled=graph_enabled,
        deleted_at=NOW if status == "deleted" else None,
        created_at=NOW,
        updated_at=NOW,
    )


class FakeCatalog:
    """A read-only catalog with explicit unknown answers."""

    def __init__(
        self,
        collections: set[str] | None = None,
        points: dict[str, int | None] | None = None,
    ) -> None:
        self.collections = collections
        self.points = points or {}

    def available_collections(self) -> set[str] | None:
        return self.collections

    def point_count(self, collection: str) -> int | None:
        return self.points.get(collection)


class FakeDatasetStore:
    """An in-memory implementation of the store contract used by the service."""

    def __init__(self) -> None:
        self.records: dict[tuple[str, str], DatasetRecord] = {}
        self.grants: set[tuple[str, str, str, str, str]] = set()
        self.tokens: dict[str, dict[str, object]] = {}
        self.source_counts: dict[tuple[str, str], dict[str, int]] = {}
        self.unavailable = False
        self.grant_error: Exception | None = None
        self.redeem_error: Exception | None = None
        self.now = NOW
        self.issued_tokens: list[str] = []

    def add(self, item: DatasetRecord) -> None:
        self.records[(item.tenant_id, item.dataset_id)] = item

    def _check(self) -> None:
        if self.unavailable:
            raise DatasetStoreUnavailableError()

    def list_active(self, tenant_id: str, limit: int) -> list[DatasetRecord]:
        self._check()
        items = [
            item
            for (owner, _), item in self.records.items()
            if owner == tenant_id and item.status == "active"
        ]
        items.sort(key=lambda item: (item.created_at, item.dataset_id), reverse=True)
        return items[:limit]

    def get(self, tenant_id: str, dataset_id: str) -> DatasetRecord | None:
        self._check()
        return self.records.get((tenant_id, dataset_id))

    def list_archived(self, limit: int = 100) -> list[DatasetRecord]:
        self._check()
        items = [item for item in self.records.values() if item.status == "archived"]
        items.sort(key=lambda item: (item.updated_at, item.dataset_id))
        return items[:limit]

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
        requested: dict[str, object],
        issue_token: bool = True,
        now: datetime | None = None,
    ) -> tuple[DatasetRecord, bool, str | None]:
        self._check()
        existing = self.records.get((tenant_id, dataset_id))
        if existing is not None:
            if existing.status == "deleted":
                raise DatasetTombstoneError()
            if existing.status != "active":
                raise DatasetInactiveError()
            for field, expected in requested.items():
                if expected is not None and getattr(existing, field) != expected:
                    raise DatasetConflictError(dataset_id, field)
            return existing, False, None

        for (owner, other_id), other in self.records.items():
            if owner != tenant_id or other_id == dataset_id:
                continue
            if other.qdrant_collection == qdrant_collection:
                raise DatasetConflictError(dataset_id, "physical_scope")
            if neo4j_namespace is not None and other.neo4j_namespace == neo4j_namespace:
                raise DatasetConflictError(dataset_id, "physical_scope")

        item = DatasetRecord(
            tenant_id=tenant_id,
            dataset_id=dataset_id,
            name=name,
            description=description,
            status="active",
            qdrant_collection=qdrant_collection,
            neo4j_namespace=neo4j_namespace,
            embedding_provider=embedding_provider,
            embedding_model=embedding_model,
            graph_enabled=graph_enabled,
            deleted_at=None,
            created_at=now or self.now,
            updated_at=now or self.now,
        )
        self.records[(tenant_id, dataset_id)] = item
        token = None
        if issue_token:
            token = self.issue_token(tenant_id, dataset_id, now=now)
        return item, True, token

    def issue_token(
        self,
        tenant_id: str,
        dataset_id: str,
        *,
        now: datetime | None = None,
    ) -> str:
        """Register one bootstrap token and return its plaintext."""

        token = TOKEN_PREFIX + secrets.token_hex(32)
        stamped = now or self.now
        self.tokens[hashlib.sha256(token.encode()).hexdigest()] = {
            "tenant_id": tenant_id,
            "dataset_id": dataset_id,
            "expires_at": stamped + TOKEN_TTL,
            "consumed_at": None,
        }
        self.issued_tokens.append(token)
        return token

    def archive(self, tenant_id: str, dataset_id: str) -> DatasetRecord | None:
        self._check()
        item = self.records.get((tenant_id, dataset_id))
        if item is None:
            return None
        if item.status == "deleted":
            return item
        archived = replace(item, status="archived", updated_at=self.now)
        self.records[(tenant_id, dataset_id)] = archived
        return archived

    def finalize_deletion(
        self, tenant_id: str, dataset_id: str
    ) -> DatasetRecord | None:
        self._check()
        item = self.records.get((tenant_id, dataset_id))
        if item is None:
            return None
        if item.status == "deleted":
            return item
        if item.status != "archived":
            raise DatasetInactiveError()
        deleted = replace(
            item, status="deleted", deleted_at=self.now, updated_at=self.now
        )
        self.records[(tenant_id, dataset_id)] = deleted
        return deleted

    def owned_source_counts(self, tenant_id: str, dataset_id: str) -> dict[str, int]:
        self._check()
        return dict(
            self.source_counts.get(
                (tenant_id, dataset_id),
                {"managed_documents": 0, "text_ingestions": 0, "url_sources": 0},
            )
        )

    def resolve_scope(self, tenant_id: str, dataset_id: str):
        self._check()
        item = self.records.get((tenant_id, dataset_id))
        return item.scope() if item is not None else None

    def has_grant(self, principal, dataset_id: str, action) -> bool:
        self._check()
        item = self.records.get((principal.tenant_id, dataset_id))
        if item is None or item.status != "active":
            return False
        return (
            principal.tenant_id,
            dataset_id,
            principal.kind.value,
            principal.principal_id,
            action.value,
        ) in self.grants

    def upsert_grant(
        self,
        tenant_id: str,
        dataset_id: str,
        principal_type: str,
        principal_id: str,
        permission: str,
    ) -> None:
        self._check()
        if self.grant_error is not None:
            raise self.grant_error
        self.grants.add(
            (tenant_id, dataset_id, principal_type, principal_id, permission)
        )

    def list_query_datasets(self, principal) -> list[DatasetRecord]:
        self._check()
        items = []
        for item in self.records.values():
            if (
                item.tenant_id != principal.tenant_id
                or item.status != "active"
                or not item.is_scope_complete()
            ):
                continue
            if (
                principal.tenant_id,
                item.dataset_id,
                principal.kind.value,
                principal.principal_id,
                "query",
            ) in self.grants:
                items.append(item)
        items.sort(key=lambda item: (item.name, item.dataset_id))
        return items

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
        self._check()
        if self.redeem_error is not None:
            raise self.redeem_error
        token_hash = hashlib.sha256(plaintext.strip().encode()).hexdigest()
        token = self.tokens.get(token_hash)
        stamped = now or self.now
        if (
            token is None
            or token["tenant_id"] != tenant_id
            or token["dataset_id"] != dataset_id
            or token["consumed_at"] is not None
            or stamped > token["expires_at"]
        ):
            raise GrantTokenInvalidError()
        token["consumed_at"] = stamped
        self.grants.add((tenant_id, dataset_id, principal_type, principal_id, "ingest"))


class FakeDatasetCleanup:
    """A recording coordinator double for service and lifespan tests."""

    def __init__(
        self,
        *,
        pending: bool = False,
        blocking: dict[str, int] | None = None,
        error: Exception | None = None,
    ) -> None:
        self.pending = pending
        self.blocking = blocking or {}
        self.error = error
        self.runs: list[tuple[str, str]] = []
        self.reconciliations = 0

    async def run(self, tenant_id: str, dataset_id: str):
        self.runs.append((tenant_id, dataset_id))
        if self.error is not None:
            raise self.error
        status = "archived" if self.pending else "deleted"
        return record(dataset_id=dataset_id, status=status), self.pending, self.blocking

    async def reconcile(self, *, limit: int = 100) -> int:
        self.reconciliations += 1
        if self.error is not None:
            raise self.error
        return 0


@dataclass
class FakeDatasetService:
    """A recording service used by the HTTP contract tests."""

    list_result: list[dict] | None = None
    creation: object | None = None
    show_result: dict | None = None
    deletion: object | None = None
    replayed: bool = False
    query_datasets_result: list[dict] | None = None
    error: Exception | None = None

    def __post_init__(self) -> None:
        self.calls: list[tuple] = []

    def _raise(self) -> None:
        if self.error is not None:
            raise self.error

    def list(self, tenant_id: str, limit: int = 50):
        self.calls.append(("list", tenant_id, limit))
        self._raise()
        return self.list_result or []

    def create(self, tenant_id: str, raw: dict):
        self.calls.append(("create", tenant_id, dict(raw)))
        self._raise()
        if self.creation is None:
            raise AssertionError("the create result is not configured")
        return self.creation

    def show(self, tenant_id: str, dataset_id: str):
        self.calls.append(("show", tenant_id, dataset_id))
        self._raise()
        return self.show_result

    async def delete(self, tenant_id: str, dataset_id: str):
        self.calls.append(("delete", tenant_id, dataset_id))
        self._raise()
        return self.deletion

    async def reconcile_cleanup(self, *, limit: int = 100) -> int:
        self.calls.append(("reconcile_cleanup", limit))
        self._raise()
        return 0

    def grant_query(self, principal, dataset_id: str):
        self.calls.append(("grant_query", principal, dataset_id))
        self._raise()

    def grant_ingest(self, principal, dataset_id: str, grant_token: str | None):
        self.calls.append(("grant_ingest", principal, dataset_id, grant_token))
        self._raise()
        return self.replayed

    def query_datasets(self, principal):
        self.calls.append(("query_datasets", principal))
        self._raise()
        return self.query_datasets_result or []


def creation(
    *,
    dataset_id: str = "dataset-a",
    token: str | None = "dgt_" + "a" * 64,
    created: bool = True,
) -> SimpleNamespace:
    """Return the parts of a creation result the API renders."""

    return SimpleNamespace(
        record=SimpleNamespace(dataset_id=dataset_id),
        grant_token=token,
        created=created,
    )


def deletion(
    *,
    dataset_id: str = "dataset-a",
    status: str = "deleted",
    pending: bool = False,
) -> SimpleNamespace:
    """Return the parts of a deletion result the API renders."""

    return SimpleNamespace(
        record=SimpleNamespace(dataset_id=dataset_id, status=status),
        cleanup_pending=pending,
        blocking_sources={},
    )
