"""Authoritative dataset management over the Python-owned schema.

The service is the only writer of dataset metadata, grants and one-time grant
tokens in Python dataset mode. It never reads Laravel's ``public`` schema and
never derives physical scope from caller input beyond the documented
compatibility fields.
"""

from __future__ import annotations

import hashlib
import re
from collections.abc import Mapping
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Protocol

from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind
from hawki_backend.authorization.models import AuthorizationAction
from hawki_backend.persistence.datasets import (
    DatasetConflictError as StoreConflictError,
)
from hawki_backend.persistence.datasets import (
    DatasetInactiveError as StoreInactiveError,
)
from hawki_backend.persistence.datasets import (
    DatasetNotFoundError as StoreNotFoundError,
)
from hawki_backend.persistence.datasets import (
    DatasetRecord,
    DatasetStore,
    DatasetStoreUnavailableError,
    DatasetTombstoneError,
    GrantTokenInvalidError as StoreGrantTokenInvalidError,
)
from hawki_backend.persistence.schema import ACTIVE_STATUS, DELETED_STATUS

from .cleanup import DatasetCleanupCoordinator
from .errors import (
    DatasetCreationConflictError,
    DatasetNotFoundError,
    DatasetNotReadyError,
    DatasetUnavailableError,
    GrantTokenInvalidError,
    GrantTokenRequiredError,
    InvalidDatasetRequestError,
)

_DATASET_ID = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:-]{0,190}$")
_PROVIDER = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]*$")
_MODEL = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._:/-]*$")
_SAFE_NAME = re.compile(r"[^a-z0-9_]+")
_CAMEL_BOUNDARY = re.compile(r"(?<!^)(?=[A-Z])")

_MAX_NAME = 255
_MAX_DESCRIPTION = 100_000
_MAX_COLLECTION = 191
_MAX_PROVIDER = 80
_MAX_MODEL = 160

# Inputs that would let a caller choose a physical target, an embedding
# contract or a scope identity. Physical scope is always server-derived; the
# migration script is the only direct-import path and does not use this API.
_FORBIDDEN_SCOPE_FIELDS = frozenset(
    {
        "qdrant_collection",
        "qdrantCollection",
        "neo4j_namespace",
        "neo4jNamespace",
        "physical_scope",
        "physicalScope",
        "embedding_provider",
        "embeddingProvider",
        "embedding_model",
        "embeddingModel",
        "graph_enabled",
        "graphEnabled",
        "tenant_id",
        "tenantId",
    }
)


class CatalogPort(Protocol):
    """The read-only physical collection questions the service needs."""

    def available_collections(self) -> set[str] | None:
        """Return available collections, or ``None`` when unknown."""
        ...

    def point_count(self, collection: str) -> int | None:
        """Return an exact point count, or ``None`` when unknown."""
        ...


@dataclass(frozen=True, slots=True)
class DatasetCreation:
    """One creation response, including the one-time bootstrap token."""

    record: DatasetRecord
    created: bool
    grant_token: str | None


@dataclass(frozen=True, slots=True)
class DatasetDeletion:
    """One archive-first deletion outcome."""

    record: DatasetRecord
    cleanup_pending: bool
    blocking_sources: Mapping[str, int]


class DatasetService:
    """The dataset authority's application service."""

    def __init__(
        self,
        store: DatasetStore,
        catalog: CatalogPort,
        *,
        embedding_provider: str,
        embedding_model: str,
        cleanup: DatasetCleanupCoordinator | None = None,
    ) -> None:
        self.store = store
        self.catalog = catalog
        self.embedding_provider = _provider(embedding_provider)
        self.embedding_model = _model(embedding_model)
        self._cleanup = cleanup

    def list(self, tenant_id: str, limit: int = 50) -> list[dict]:
        """Return the tenant's active datasets, newest first."""

        try:
            records = self.store.list_active(tenant_id, max(1, min(250, limit)))
        except DatasetStoreUnavailableError:
            raise DatasetUnavailableError() from None
        available = self.catalog.available_collections()
        return [
            self._payload(record, available=available, include_details=False)
            for record in records
        ]

    def show(self, tenant_id: str, dataset_id: str) -> dict | None:
        """Return one active dataset with its details, or ``None``."""

        record = self._active(tenant_id, dataset_id)
        return self._payload(
            record,
            available=self.catalog.available_collections(),
            include_details=True,
        )

    def create(self, tenant_id: str, raw: Mapping[str, object]) -> DatasetCreation:
        """Create one dataset and issue its bootstrap token exactly once."""

        dataset_id = _dataset_id(raw)
        _reject_caller_scope(raw)
        explicit_name = _text(raw, "name", _MAX_NAME)
        description = _description(raw)
        status = _text(raw, "status", 64)
        if status is not None and status != ACTIVE_STATUS:
            raise DatasetCreationConflictError(dataset_id, "status")
        # Physical scope is server-derived and tenant-aware. Caller input can
        # never select or reserve a collection or namespace.
        collection, namespace = physical_scope(tenant_id, dataset_id)
        name = explicit_name or _display_name(dataset_id)

        requested: dict[str, object] = {}
        for field, value in (
            ("name", explicit_name),
            ("description", description),
            ("status", status),
        ):
            if value is not None:
                requested[field] = value

        try:
            record, created, token = self.store.create(
                tenant_id,
                dataset_id=dataset_id,
                name=name,
                description=description,
                qdrant_collection=collection,
                neo4j_namespace=namespace,
                embedding_provider=self.embedding_provider,
                embedding_model=self.embedding_model,
                graph_enabled=False,
                requested=requested,
            )
        except StoreConflictError as error:
            raise DatasetCreationConflictError(error.dataset_id, error.field) from None
        except (StoreInactiveError, DatasetTombstoneError):
            raise DatasetCreationConflictError(dataset_id, "status") from None
        except DatasetStoreUnavailableError:
            raise DatasetUnavailableError() from None
        return DatasetCreation(record, created, token)

    async def delete(self, tenant_id: str, dataset_id: str) -> DatasetDeletion | None:
        """Archive a dataset, then drive every owned source through deletion.

        The archived row is the durable work item: the coordinator reuses each
        source's existing deletion protocol and only tombstones the dataset
        once no owned source row remains. A second call is an idempotent retry.
        """

        try:
            record = self.store.archive(tenant_id, dataset_id)
            if record is None:
                return None
            if record.status == DELETED_STATUS:
                return DatasetDeletion(record, False, {})
            if self._cleanup is None:
                counts = self.store.owned_source_counts(tenant_id, dataset_id)
                blocking = {name: value for name, value in counts.items() if value}
                if blocking:
                    # Without the source protocols we cannot delete the sources
                    # and must not claim a completed deletion.
                    raise DatasetUnavailableError()
                final = self.store.finalize_deletion(tenant_id, dataset_id)
                return DatasetDeletion(final or record, False, {})
            cleaned, pending, blocking = await self._cleanup.run(tenant_id, dataset_id)
            return DatasetDeletion(cleaned, pending, blocking)
        except DatasetStoreUnavailableError:
            raise DatasetUnavailableError() from None

    async def reconcile_cleanup(self, *, limit: int = 100) -> int:
        """Re-drive durable archived rows on startup and periodically."""

        if self._cleanup is None:
            return 0
        try:
            return await self._cleanup.reconcile(limit=limit)
        except DatasetStoreUnavailableError:
            raise DatasetUnavailableError() from None

    def grant_query(
        self,
        principal: AuthenticatedPrincipal,
        dataset_id: str,
    ) -> DatasetRecord:
        """Grant the current user query access to one ready active dataset."""

        record = self._active(principal.tenant_id, dataset_id)
        try:
            has_grant = self.store.has_grant(
                principal, record.dataset_id, AuthorizationAction.QUERY
            )
        except DatasetStoreUnavailableError:
            raise DatasetUnavailableError() from None
        if has_grant:
            self._upsert(record, principal, AuthorizationAction.QUERY)
            return record
        scope = record.scope()
        available = self.catalog.available_collections()
        if not scope or available is None or scope.qdrant_collection not in available:
            raise DatasetNotReadyError()
        points = self.catalog.point_count(scope.qdrant_collection)
        if points is None or points < 1:
            raise DatasetNotReadyError()
        self._upsert(record, principal, AuthorizationAction.QUERY)
        return record

    def grant_ingest(
        self,
        principal: AuthenticatedPrincipal,
        dataset_id: str,
        grant_token: str | None,
    ) -> bool:
        """Grant ingest access, returning whether an existing grant replayed."""

        record = self._active(principal.tenant_id, dataset_id)
        try:
            has_grant = self.store.has_grant(
                principal, record.dataset_id, AuthorizationAction.INGEST
            )
        except DatasetStoreUnavailableError:
            raise DatasetUnavailableError() from None
        if has_grant:
            self._upsert(record, principal, AuthorizationAction.INGEST)
            return True
        if not grant_token:
            raise GrantTokenRequiredError()
        try:
            self.store.redeem_grant_token(
                principal.tenant_id,
                record.dataset_id,
                principal.kind.value,
                principal.principal_id,
                grant_token,
            )
        except (StoreNotFoundError, StoreInactiveError):
            # Redemption is an admission decision; the dataset became inactive
            # before the token transaction could commit.
            raise DatasetNotFoundError() from None
        except StoreGrantTokenInvalidError:
            raise GrantTokenInvalidError() from None
        except DatasetStoreUnavailableError:
            raise DatasetUnavailableError() from None
        return False

    def query_datasets(self, principal: AuthenticatedPrincipal) -> list[dict[str, str]]:
        """Return exactly the datasets the principal may query right now."""

        if principal.kind is not PrincipalKind.USER:
            return []
        try:
            records = self.store.list_query_datasets(principal)
        except DatasetStoreUnavailableError:
            raise DatasetUnavailableError() from None
        available = self.catalog.available_collections()
        if available is None:
            return []
        return [
            {"dataset_id": record.dataset_id, "name": record.name}
            for record in records
            if record.qdrant_collection in available
        ]

    def _active(self, tenant_id: str, dataset_id: str) -> DatasetRecord:
        """Return an active dataset or the same generic not-found refusal."""

        if not _DATASET_ID.fullmatch(dataset_id):
            raise DatasetNotFoundError()
        try:
            record = self.store.get(tenant_id, dataset_id)
        except DatasetStoreUnavailableError:
            raise DatasetUnavailableError() from None
        if record is None or record.status != ACTIVE_STATUS:
            raise DatasetNotFoundError()
        return record

    def _upsert(
        self,
        record: DatasetRecord,
        principal: AuthenticatedPrincipal,
        action: AuthorizationAction,
    ) -> None:
        try:
            self.store.upsert_grant(
                record.tenant_id,
                record.dataset_id,
                principal.kind.value,
                principal.principal_id,
                action.value,
            )
        except (StoreNotFoundError, StoreInactiveError):
            # The dataset was archived between the read and the fenced write.
            raise DatasetNotFoundError() from None
        except DatasetStoreUnavailableError:
            raise DatasetUnavailableError() from None

    def _payload(
        self,
        record: DatasetRecord,
        *,
        available: set[str] | None,
        include_details: bool,
    ) -> dict:
        try:
            counts = self.store.owned_source_counts(record.tenant_id, record.dataset_id)
        except DatasetStoreUnavailableError:
            raise DatasetUnavailableError() from None
        payload = {
            "id": None,
            "dataset_id": record.dataset_id,
            "name": record.name,
            "description": record.description,
            "status": record.status,
            "qdrant_collection": record.qdrant_collection,
            "neo4j_namespace": record.neo4j_namespace,
            "embedding_provider": record.embedding_provider,
            "embedding_model": record.embedding_model,
            "created_at": _timestamp(record.created_at),
            "document_count": sum(counts.values()),
            "task_count": 0,
            "last_ingestion": None,
            "graph_stats": {
                "qdrant": self._qdrant_stats(record, available),
                "neo4j": {
                    "ok": False,
                    "error": "Neo4j statistics are not available in dataset authority mode.",
                },
            },
        }
        if include_details:
            payload["tasks"] = []
            payload["ingestion_history"] = []
        return payload

    def _qdrant_stats(
        self,
        record: DatasetRecord,
        available: set[str] | None,
    ) -> dict:
        collection = record.qdrant_collection
        if available is None:
            return {
                "ok": False,
                "collection": collection,
                "points": None,
                "error": "Qdrant statistics are not available.",
            }
        if collection not in available:
            return {
                "ok": True,
                "collection": collection,
                "points": 0,
                "status": "not_created",
                "message": "Collection not created yet",
            }
        points = self.catalog.point_count(collection)
        if points is None:
            return {
                "ok": False,
                "collection": collection,
                "points": None,
                "error": "Qdrant statistics are not available.",
            }
        return {"ok": True, "collection": collection, "points": points}


def _dataset_id(raw: Mapping[str, object]) -> str:
    value = raw.get("dataset_id", raw.get("datasetId"))
    if value is None:
        return "default"
    if not isinstance(value, str):
        raise InvalidDatasetRequestError()
    text = value.strip()
    if not text:
        return "default"
    if not _DATASET_ID.fullmatch(text):
        raise DatasetCreationConflictError(text, "dataset_id")
    return text


def _text(raw: Mapping[str, object], key: str, maximum: int) -> str | None:
    value = raw.get(key)
    if value is None:
        return None
    if not isinstance(value, str):
        raise InvalidDatasetRequestError()
    text = value.strip()
    if not text:
        return None
    if len(text) > maximum:
        raise InvalidDatasetRequestError()
    return text


def _explicit_scope_field(raw: Mapping[str, object]) -> str | None:
    for field in _FORBIDDEN_SCOPE_FIELDS:
        if field in raw:
            return field
    return None


def _reject_caller_scope(raw: Mapping[str, object]) -> None:
    """Refuse every caller-selected physical, embedding or scope identity."""

    if _explicit_scope_field(raw) is not None:
        raise InvalidDatasetRequestError()


def physical_scope(tenant_id: str, dataset_id: str) -> tuple[str, str]:
    """Derive one bounded, tenant-aware physical target for both stores.

    The collection and the graph namespace deliberately share the value: one
    dataset maps to exactly one physical identity. The digest binds the
    authenticated tenant, so two tenants can never collide by choosing the
    same dataset id, and a caller can neither select nor reserve a target.
    """

    digest = hashlib.sha256(
        tenant_id.encode("utf-8") + b"\0" + dataset_id.encode("utf-8")
    ).hexdigest()[:16]
    slug = _safe_name(dataset_id)[: _MAX_COLLECTION - len("hawki__") - len(digest)]
    value = f"hawki_{slug}_{digest}"
    return value, value


def _description(raw: Mapping[str, object]) -> str | None:
    value = raw.get("description")
    if value is None:
        return None
    if not isinstance(value, str):
        raise InvalidDatasetRequestError()
    text = value.strip()
    if not text:
        return None
    if len(text) > _MAX_DESCRIPTION:
        raise InvalidDatasetRequestError()
    return text


def _safe_name(dataset_id: str) -> str:
    safe = _SAFE_NAME.sub("_", dataset_id.lower()).strip("_")
    return safe or "default"


def _display_name(dataset_id: str) -> str:
    spaced = _CAMEL_BOUNDARY.sub(" ", dataset_id.replace("_", " ").replace("-", " "))
    return " ".join(word.capitalize() for word in spaced.split()) or dataset_id


def _provider(value: str) -> str:
    text = value.strip().lower()
    if not text or len(text) > _MAX_PROVIDER or not _PROVIDER.fullmatch(text):
        raise ValueError("embedding provider is not valid")
    return text


def _model(value: str) -> str:
    text = value.strip()
    if not text or len(text) > _MAX_MODEL or not _MODEL.fullmatch(text):
        raise ValueError("embedding model is not valid")
    return text


def _timestamp(value: datetime) -> str:
    if value.tzinfo is None or value.utcoffset() is None:
        value = value.replace(tzinfo=timezone.utc)
    return value.astimezone(timezone.utc).isoformat()


__all__ = [
    "DatasetCreation",
    "DatasetDeletion",
    "DatasetService",
]
