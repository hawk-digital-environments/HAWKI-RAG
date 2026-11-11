"""Authoritative direct-text operations, outside SQL work and HTTP requests.

The service is the final authorization authority for the migrated operation.
It decides the dataset grant before it writes an artifact or a row, keeps the
idempotency claim and the workflow preparation transactional, and owns the
lifecycle of the direct-text source. Only the signed worker event moves a
prepared operation to ``ready`` or ``failed``.
"""

from __future__ import annotations

import hashlib
import logging
import os
from collections.abc import Mapping
from dataclasses import dataclass
from typing import Any

from temporalio.exceptions import WorkflowAlreadyStartedError

from hawki_backend.auth.models import AuthenticatedPrincipal
from hawki_backend.authorization import (
    AuthorizationAction,
    AuthorizationPort,
    AuthorizationRequest,
    AuthorizationUnavailableError,
)
from hawki_backend.query.settings import DatasetScope, ProviderModels, QuerySettings
from hawki_backend.persistence.datasets import (
    DatasetInactiveError as StoreInactiveError,
)
from hawki_backend.persistence.datasets import (
    DatasetNotFoundError as StoreNotFoundError,
)
from hawki_backend.workflow_control.repository import OperationConflict
from hawki_backend.workflow_control.service import (
    DispatchUncertain,
    DispatchUnavailable,
    WorkflowControl,
)
from hawki_workflow_control.temporal_client import TemporalUnavailable

from . import identity
from .deletion import DeletionSnapshot
from .artifacts import StoredTextArtifact, TextArtifactStorage
from .repository import (
    EventCollision,
    OperationNotFound,
    SourceBusy,
    TextOperationRepository,
)
from .schema import (
    STATUS_ACCEPTED,
    STATUS_DELETED,
    STATUS_DELETE_FAILED,
    STATUS_DELETING,
    STATUS_FAILED,
    STATUS_READY,
)
from .settings import TextIngestionSettings
from .validation import TextIngestionRequest
from .vectors import TextVectorDeletion
from .writers import WriterEvent

logger = logging.getLogger(__name__)


class IdempotencyConflict(Exception):
    """The key was replayed with a different canonical request."""


class DatasetNotReady(Exception):
    """The dataset is authorized but has no trusted physical scope."""


class WorkflowUnavailable(Exception):
    """The workflow-control plane is not configured or cannot accept work."""


class WorkflowUncertain(Exception):
    """Dispatch is unconfirmed; retry the same key and reconcile."""


class DeletionFailed(Exception):
    """Cleanup did not complete; the source remains delete_failed."""


class NotFound(Exception):
    """No owned direct-text operation matches the request."""


class DatasetNotFound(Exception):
    """The principal has no ingest grant on an active dataset."""


class Busy(Exception):
    """Another operation owns the source."""


@dataclass(frozen=True, slots=True)
class IngestOutcome:
    task_id: str
    job_id: str
    source_id: str
    workflow_id: str
    status: str
    replayed: bool

    def to_payload(self) -> dict[str, Any]:
        return {
            "task_id": self.task_id,
            "job_id": self.job_id,
            "source_id": self.source_id,
            "workflow_id": self.workflow_id,
            "status": self.status,
            "replayed": self.replayed,
        }


@dataclass(frozen=True, slots=True)
class DeleteOutcome:
    source_id: str
    dataset_id: str
    replayed: bool

    def to_payload(self) -> dict[str, Any]:
        return {
            "source_id": self.source_id,
            "dataset_id": self.dataset_id,
            "status": "deleted",
            "deleted": True,
            "replayed": self.replayed,
        }


@dataclass(frozen=True, slots=True)
class EventOutcome:
    event_id: str
    duplicate: bool
    ignored: bool

    def to_payload(self) -> dict[str, Any]:
        return {
            "event_id": self.event_id,
            "accepted": True,
            "duplicate": self.duplicate,
            "ignored": self.ignored,
        }


class TextIngestionService:
    """Own the direct-text operation from authorization to verified deletion."""

    def __init__(
        self,
        *,
        repository: TextOperationRepository,
        artifacts: TextArtifactStorage,
        vectors: TextVectorDeletion,
        settings: TextIngestionSettings,
        policy: AuthorizationPort | None,
        query_settings: QuerySettings | None,
        workflow_control: WorkflowControl | None,
        chunk_size: int = 1200,
        chunk_overlap: int = 250,
        batch_size: int = 64,
    ) -> None:
        self._repository = repository
        self._artifacts = artifacts
        self._vectors = vectors
        self._settings = settings
        self._policy = policy
        self._query_settings = query_settings
        self._workflow_control = workflow_control
        self._chunk_size = chunk_size
        self._chunk_overlap = chunk_overlap
        self._batch_size = batch_size

    async def ingest(
        self,
        principal: AuthenticatedPrincipal,
        request: TextIngestionRequest,
        idempotency_key: str,
    ) -> IngestOutcome:
        """Authorize, claim and dispatch one idempotent direct-text operation."""
        self._authorize(principal, request.dataset_id)
        fingerprint = request.fingerprint()

        existing = self._repository.find(principal.tenant_id, idempotency_key)
        if existing is not None:
            if existing["fingerprint"] != fingerprint:
                raise IdempotencyConflict()
            return await self._resume(existing)

        if self._workflow_control is None:
            raise WorkflowUnavailable()

        scope, models = self._scope(request.dataset_id, principal.tenant_id)
        source = identity.source_id(request.dataset_id, request.external_document_id)
        task = identity.task_id(request.dataset_id, idempotency_key)
        job = identity.job_id(task, source)
        workflow = identity.workflow_id(task)
        artifact = StoredTextArtifact(
            source_id=source,
            source_url=request.source_url
            or f"external://{request.external_document_id}",
            content_hash=identity.content_hash(request.text),
            markdown_path=os.path.join(
                self._settings.shared_root,
                "sources",
                source,
                "revisions",
                fingerprint,
                "markdown",
                "document.md",
            ),
        )

        def store_artifact() -> None:
            self._artifacts.store(
                dataset_id=request.dataset_id,
                external_document_id=request.external_document_id,
                fingerprint=fingerprint,
                text=request.text,
                content_format=request.content_format,
                display_name=request.display_name,
                source_url=request.source_url,
                metadata=request.metadata,
            )

        values = {
            "tenant_id": principal.tenant_id,
            "idempotency_key": idempotency_key,
            "principal_type": principal.kind.value,
            "principal_id": principal.principal_id,
            "dataset_id": request.dataset_id,
            "external_document_id": request.external_document_id,
            "source_id": artifact.source_id,
            "fingerprint": fingerprint,
            "content_hash": artifact.content_hash,
            "markdown_path": artifact.markdown_path,
            "task_id": task,
            "job_id": job,
            "workflow_id": workflow,
            "run_id": None,
            "lifecycle_status": STATUS_ACCEPTED,
            "request_metadata": request.request_metadata(idempotency_key),
            "deletion_key": None,
            "deleted_at": None,
        }

        # Freeze the server-selected physical targets and wire input with the
        # operation. Configuration drift cannot redirect a retry or cleanup.
        values["request_metadata"]["workflow_input"] = self._payload(
            values, scope, models
        )
        values["request_metadata"].update(
            {
                "managed_writer_protocol": 1,
                "managed_writer_gate": "open",
                "managed_writers": {},
            }
        )

        try:
            row, created = self._repository.claim(values, store_artifact)
        except SourceBusy:
            raise Busy() from None
        except (StoreNotFoundError, StoreInactiveError):
            raise DatasetNotFound() from None

        if not created:
            if row["fingerprint"] != fingerprint:
                raise IdempotencyConflict()
            return await self._resume(row)

        self._artifacts.clear_reconciliation_marker(artifact)
        return await self._dispatch(row, replayed=False)

    async def delete(
        self,
        principal: AuthenticatedPrincipal,
        source_id: str,
        idempotency_key: str,
    ) -> DeleteOutcome:
        """Delete one owned direct-text source after verifying its grant."""
        row = self._repository.find_by_source(principal.tenant_id, source_id)
        if row is None:
            raise NotFound()
        dataset_id = str(row["dataset_id"])
        self._authorize(principal, dataset_id)
        try:
            snapshot, replayed, workflow_closed = self._repository.prepare_delete(
                tenant_id=principal.tenant_id,
                source_id=source_id,
                deletion_key=idempotency_key,
                dataset_id=dataset_id,
            )
        except OperationNotFound:
            raise NotFound() from None
        except SourceBusy:
            raise Busy() from None
        except (StoreNotFoundError, StoreInactiveError):
            raise DatasetNotFound() from None
        if replayed:
            return DeleteOutcome(
                source_id=source_id, dataset_id=dataset_id, replayed=True
            )
        return await self._cleanup(
            snapshot,
            replayed=False,
            workflow_closed=workflow_closed,
        )

    async def cleanup_dataset(self, tenant_id: str, dataset_id: str) -> None:
        """Drive every owned direct-text source through its deletion protocol.

        This is the narrow internal cleanup authority used only after the
        dataset row has been archived. It never grants, widens or reads a user
        authorization; it reuses the same durable deletion state machine and
        leaves failed cleanup retryable by the reconciler.
        """

        for source_id in self._repository.list_blocking_sources(tenant_id, dataset_id):
            latest = self._repository.find_by_source(tenant_id, source_id)
            if latest is None or latest["lifecycle_status"] == STATUS_DELETED:
                continue
            identity = str(latest["idempotency_key"])
            digest_input = "\0".join(
                (tenant_id, dataset_id, source_id, identity)
            ).encode("utf-8")
            deletion_key = "dataset-cleanup:" + hashlib.sha256(digest_input).hexdigest()
            try:
                snapshot, replayed, workflow_closed = self._repository.prepare_delete(
                    tenant_id=tenant_id,
                    source_id=source_id,
                    deletion_key=deletion_key,
                    dataset_id=dataset_id,
                    require_active_dataset=False,
                )
            except OperationNotFound:
                continue
            except SourceBusy:
                # Another mutation still owns the source. Leave it archived
                # and retryable; the reconciler will run the same protocol.
                continue
            if replayed:
                continue
            await self._cleanup(
                snapshot,
                replayed=False,
                workflow_closed=workflow_closed,
            )

    async def complete(
        self,
        *,
        event_id: str,
        payload_hash: str,
        workflow_id: str,
        source_id: str,
        status: str,
        job_id: str,
        task_id: str | None,
        run_id: str,
    ) -> EventOutcome:
        """Record one signed worker event and project its terminal state."""
        row = self._repository.find_by_workflow(workflow_id)
        if row is None or row["source_id"] != source_id:
            raise NotFound()
        if row["job_id"] != job_id or (
            task_id is not None and row["task_id"] != task_id
        ):
            raise NotFound()

        if row["run_id"] is not None and row["run_id"] != run_id:
            raise NotFound()

        terminal = None
        if status == STATUS_READY:
            terminal = STATUS_READY
        elif status == STATUS_FAILED:
            terminal = STATUS_FAILED
        try:
            updated, duplicate, ignored = self._repository.complete(
                event_id=event_id,
                payload_hash=payload_hash,
                workflow_id=workflow_id,
                source_id=source_id,
                status=terminal or "running",
                run_id=run_id,
            )
        except OperationNotFound:
            raise NotFound() from None
        except EventCollision:
            raise
        logger.info(
            "direct-text worker event recorded source_id=%s status=%s duplicate=%s ignored=%s",
            source_id,
            updated["lifecycle_status"],
            duplicate,
            ignored,
        )
        return EventOutcome(event_id=event_id, duplicate=duplicate, ignored=ignored)

    def record_writer(self, event: WriterEvent) -> dict[str, Any]:
        """Persist the existing managed-writer receipt in Python-owned state."""
        return self._repository.record_writer(event)

    async def _resume(
        self,
        row: Mapping[str, Any],
    ) -> IngestOutcome:
        status = str(row["lifecycle_status"])
        if status == STATUS_DELETED:
            return self._outcome(row, "deleted", replayed=True)
        if status in {STATUS_DELETING, STATUS_DELETE_FAILED}:
            return self._outcome(row, "deleting", replayed=True)
        if status in {STATUS_READY, STATUS_FAILED}:
            return self._outcome(row, status, replayed=True)
        # An accepted, started or failed operation is safe to reissue with the
        # same deterministic identity; Epoch 8 reconciles an uncertain
        # dispatch and never creates a second execution.
        return await self._dispatch(row, replayed=True)

    async def _dispatch(
        self,
        row: Mapping[str, Any],
        *,
        replayed: bool,
    ) -> IngestOutcome:
        control = self._workflow_control
        if control is None:
            raise WorkflowUnavailable()
        payload = row["request_metadata"].get("workflow_input")
        if not isinstance(payload, dict):
            raise WorkflowUnavailable()
        workflow_id = str(row["workflow_id"])
        try:
            receipt = await control.start("text", workflow_id, payload)
        except OperationConflict:
            raise WorkflowUncertain() from None
        except DispatchUnavailable:
            raise WorkflowUnavailable() from None
        except DispatchUncertain:
            raise WorkflowUncertain() from None
        except WorkflowAlreadyStartedError:
            raise WorkflowUncertain() from None
        except TemporalUnavailable:
            raise WorkflowUnavailable() from None

        run_id = receipt.get("run_id")
        if not isinstance(run_id, str) or not run_id:
            raise WorkflowUncertain()
        self._repository.record_dispatch(
            str(row["tenant_id"]),
            str(row["idempotency_key"]),
            run_id,
        )
        return self._outcome(row, "running", replayed=replayed, workflow_id=workflow_id)

    async def _cleanup(
        self,
        snapshot: DeletionSnapshot,
        *,
        replayed: bool,
        workflow_closed: bool,
    ) -> DeleteOutcome:
        source_id = snapshot.source_id
        dataset_id = snapshot.dataset_id
        try:
            if not workflow_closed and snapshot.run_id:
                await self._cancel(
                    {
                        "workflow_id": snapshot.workflow_id,
                        "run_id": snapshot.run_id,
                        "source_id": source_id,
                    }
                )
            elif not workflow_closed and snapshot.workflow_id:
                raise DeletionFailed()
            # Cancellation is deliberately not the drainage proof. The durable
            # managed-writer gate was closed in prepare_delete; every admitted
            # writer must acknowledge release before cleanup can begin.
            self._repository.require_drained(snapshot)
            self._vectors.delete(
                collection=snapshot.collection,
                dataset_id=dataset_id,
                source_id=source_id,
                document_id=snapshot.document_id,
                operation_key=snapshot.deletion_key,
            )
            self._repository.finish_delete(
                snapshot,
                lambda: self._artifacts.delete_source_artifacts(
                    source_id, markdown_path=snapshot.markdown_path
                ),
            )
        except Exception as error:
            self._mark_delete_failed(snapshot, error)
            raise DeletionFailed() from None
        logger.info(
            "direct-text source deleted source_id=%s dataset_id=%s",
            source_id,
            dataset_id,
        )
        return DeleteOutcome(
            source_id=source_id,
            dataset_id=dataset_id,
            replayed=replayed,
        )

    async def _cancel(self, row: Mapping[str, Any]) -> None:
        control = self._workflow_control
        if control is None:
            raise DeletionFailed()
        try:
            await control.port.cancel_workflow_and_wait(
                workflow_id=str(row["workflow_id"]),
                run_id=row["run_id"],
            )
        except Exception as error:
            logger.warning(
                "direct-text workflow cancellation failed source_id=%s error=%s",
                row["source_id"],
                type(error).__name__,
            )
            raise

    def _mark_delete_failed(self, snapshot: DeletionSnapshot, error: Exception) -> None:
        logger.warning(
            "direct-text deletion did not complete source_id=%s error=%s",
            snapshot.source_id,
            type(error).__name__,
        )
        try:
            self._repository.fail_delete(snapshot)
        except Exception as marker_error:  # noqa: BLE001 - stable failure
            logger.error(
                "direct-text deletion state could not be recorded source_id=%s error=%s",
                snapshot.source_id,
                type(marker_error).__name__,
            )

    def _authorize(self, principal: AuthenticatedPrincipal, dataset_id: str) -> None:
        policy = self._policy
        if policy is None:
            raise WorkflowUnavailable()
        try:
            allowed = policy.decide(
                AuthorizationRequest(
                    principal=principal,
                    action=AuthorizationAction.INGEST,
                    dataset_id=dataset_id,
                )
            )
        except AuthorizationUnavailableError:
            raise
        if not allowed:
            raise DatasetNotFound()
        return

    def _scope(
        self, dataset_id: str, tenant_id: str
    ) -> tuple[DatasetScope, ProviderModels]:
        settings = self._query_settings
        if settings is None:
            raise DatasetNotReady()
        scope = settings.resolve_scope(dataset_id, tenant_id)
        if scope is None:
            raise DatasetNotReady()
        models = settings.models_for(scope.embedding_provider)
        if models is None:
            raise DatasetNotReady()
        return scope, models

    def _payload(
        self,
        row: Mapping[str, Any],
        scope: DatasetScope,
        models: ProviderModels,
    ) -> dict[str, Any]:
        request = row["request_metadata"]
        metadata = request if isinstance(request, dict) else {}
        source_url = _optional_text(metadata.get("source_url")) or (
            "external://" + str(row["external_document_id"])
        )
        markdown_path = str(row["markdown_path"])
        markdown_directory = os.path.dirname(markdown_path)
        return {
            "text_writer_protocol": 1,
            "source_id": str(row["source_id"]),
            "source_url": source_url,
            "task_id": str(row["task_id"]),
            "job_id": str(row["job_id"]),
            "dataset_id": str(row["dataset_id"]),
            "external_document_id": str(row["external_document_id"]),
            "markdown_path": markdown_path,
            "markdown_output_path": markdown_directory,
            "ingest_manifest_path": os.path.join(
                os.path.dirname(markdown_directory),
                "ingest",
                "manifest.json",
            ),
            "content_hash": str(row["content_hash"]),
            "display_name": _optional_text(metadata.get("display_name")),
            "storage": {
                "mode": "shared",
                "shared_root": self._settings.shared_root,
            },
            "ingestion": {
                "provider": scope.embedding_provider,
                "embedding_model": scope.embedding_model,
                # Direct text never writes a graph. The legacy default graph
                # model resolves to the provider's chat model; the worker
                # ignores this value while ``graph`` is false.
                "graph_model": models.chat_model,
                "vision_model": models.vision_model,
                "graph": False,
                "collection": scope.qdrant_collection,
                "neo4j_namespace": scope.neo4j_namespace,
                "chunk_chars": self._chunk_size,
                "chunk_overlap": self._chunk_overlap,
                "batch_size": self._batch_size,
            },
        }

    @staticmethod
    def _outcome(
        row: Mapping[str, Any],
        status: str,
        *,
        replayed: bool,
        workflow_id: str | None = None,
    ) -> IngestOutcome:
        return IngestOutcome(
            task_id=str(row["task_id"]),
            job_id=str(row["job_id"]),
            source_id=str(row["source_id"]),
            workflow_id=workflow_id or str(row["workflow_id"]),
            status=status,
            replayed=replayed,
        )


def _optional_text(value: object) -> str | None:
    return value if isinstance(value, str) and value else None


__all__ = [
    "Busy",
    "DatasetNotFound",
    "DatasetNotReady",
    "DeleteOutcome",
    "DeletionFailed",
    "EventOutcome",
    "IdempotencyConflict",
    "IngestOutcome",
    "NotFound",
    "TextIngestionService",
    "WorkflowUnavailable",
    "WorkflowUncertain",
]
