"""Durable permission to write managed sinks; ambiguous writes never release it."""

from collections.abc import Iterator
from contextlib import contextmanager
from dataclasses import replace
from threading import Condition
import hashlib
from typing import Any

from temporalio import activity
from temporalio.exceptions import CancelledError

from hawki_pipeline_callbacks import LaravelCallbackClient, LaravelCallbackSettings
from hawki_indexer_worker.adapters.neo4j_writer import create_registered_graph_writer
from hawki_indexer_worker.adapters.qdrant_writer import RegisteredVectorWriter
from hawki_indexer_worker.domain.ports import VectorWriterPort, GraphWriterPort
from hawki_indexer_worker.indexing.dependencies import IngestWorkflowDependencies
from hawki_indexer_worker.settings import IndexerSettings


class ManagedWriterRegistration:
    """A source-locked ledger entry survives cancellation, crashes and worker restarts."""

    def __init__(
        self, settings: IndexerSettings, workflow_input: dict[str, Any], info: Any
    ) -> None:
        self.settings = settings
        self.workflow_input = workflow_input
        self.info = info
        self.uncertain = False
        self.mutation_started = False
        self.targets: dict[str, str | None] = {}
        self._writes = Condition()
        self._inflight = 0
        self._closing = False

    def check_cancelled(self) -> None:
        if activity.in_activity():
            activity.heartbeat()
            if activity.is_cancelled():
                raise CancelledError("Managed indexing was canceled")

    def send(self, action: str, targets: list[dict[str, Any]]) -> dict[str, Any]:
        if action == "acquire":
            self.check_cancelled()
        identity = f"{self.info.workflow_id}|{self.info.workflow_run_id}|{self.info.activity_id}|{self.info.attempt}"
        suffix = hashlib.sha256(repr(targets).encode()).hexdigest()[:16]
        event = {
            "schema_version": 1,
            "event_id": hashlib.sha256(identity.encode()).hexdigest()
            + f":{action}:{suffix}",
            "action": action,
            "workflow_id": self.info.workflow_id,
            "run_id": self.info.workflow_run_id,
            "activity_id": self.info.activity_id,
            "attempt": self.info.attempt,
            "source_id": self.workflow_input["source_id"],
            "job_id": self.workflow_input["job_id"],
            "targets": targets,
        }
        with LaravelCallbackClient(
            LaravelCallbackSettings(
                endpoint=self.settings.callback_url.rsplit("/", 1)[0]
                + "/managed-writers",
                secret=self.settings.callback_secret,
                timeout_seconds=self.settings.callback_timeout_seconds,
                retry_attempts=self.settings.callback_retry_attempts,
            )
        ) as sender:
            receipt = sender.send(event)
        expected = "active" if action == "acquire" else "released"
        if receipt.get("success") is not True or receipt.get("status") != expected:
            raise RuntimeError("Managed sink writer permission was not acknowledged")
        return receipt

    def register_targets(self, targets: list[dict[str, Any]]) -> None:
        receipt = self.send("acquire", targets)
        if not targets:
            return
        grants = receipt.get("targets", [])
        scope = self.workflow_input.get("ingestion") or {}
        requested = {target["doc_id"]: target for target in targets}
        if len(grants) != len(requested):
            raise RuntimeError("Managed target scope was not acknowledged")
        accepted = {}
        for grant in grants:
            doc_id = grant.get("doc_id")
            namespace = grant.get("neo4j_namespace")
            target = requested.get(doc_id)
            if (
                not target
                or doc_id in accepted
                or grant.get("dataset_id") != self.workflow_input.get("dataset_id")
                or grant.get("source_id") != self.workflow_input["source_id"]
                or grant.get("collection") != scope.get("collection")
                or namespace not in {None, scope.get("neo4j_namespace")}
                or ((target.get("graph") or scope.get("graph")) and not namespace)
                or (self.targets.get(doc_id) and namespace != self.targets[doc_id])
            ):
                raise RuntimeError("Managed target grant conflicts with indexing scope")
            accepted[doc_id] = namespace
        self.targets.update(accepted)

    def authorize_vector(self, payload: dict[str, Any]) -> None:
        self.check_cancelled()
        if (
            payload.get("doc_id") not in self.targets
            or payload.get("source_id") != self.workflow_input["source_id"]
            or payload.get("dataset_id") != self.workflow_input.get("dataset_id")
        ):
            raise RuntimeError("Vector mutation exceeds registered target scope")
        self.mutation_started = True

    def authorize_graph(self, doc_id: str, dataset_id: str, namespace: str) -> None:
        self.check_cancelled()
        if (
            not namespace
            or self.targets.get(doc_id) != namespace
            or dataset_id != self.workflow_input.get("dataset_id")
        ):
            raise RuntimeError("Graph mutation exceeds registered target scope")
        self.mutation_started = True

    @contextmanager
    def sink_write(self) -> Iterator[None]:
        """Close admission before release, and drain every admitted sink call."""
        with self._writes:
            if self._closing:
                raise RuntimeError(
                    "Managed writer has finished; sink mutations are closed"
                )
            self._inflight += 1
        try:
            yield
        finally:
            with self._writes:
                self._inflight -= 1
                self._writes.notify_all()

    def failed_write(self) -> None:
        with self._writes:
            self.uncertain = True

    def release(self) -> None:
        with self._writes:
            self._closing = True
            while self._inflight:
                self._writes.wait()
        if self.uncertain:
            raise RuntimeError(
                "A managed sink acknowledgement is uncertain; writer reconciliation is required"
            )
        self.send("release", [])

    def vector_writer(self) -> VectorWriterPort:
        if not self.targets:
            raise RuntimeError("Vector client requires acknowledged targets")
        return RegisteredVectorWriter(
            self.failed_write,
            collection=(self.workflow_input.get("ingestion") or {}).get("collection"),
            source_id=self.workflow_input["source_id"],
            dataset_id=self.workflow_input["dataset_id"],
            authorize=self.authorize_vector,
            write_guard=self.sink_write,
        )

    def graph_writer(
        self,
        database: str | None = None,
        *,
        dataset_id: str | None = None,
        neo4j_namespace: str | None = None,
    ) -> GraphWriterPort:
        scope = self.workflow_input.get("ingestion") or {}
        if (
            not neo4j_namespace
            or neo4j_namespace not in self.targets.values()
            or dataset_id != self.workflow_input.get("dataset_id")
            or neo4j_namespace != scope.get("neo4j_namespace")
            or database != scope.get("neo4j_database")
        ):
            raise RuntimeError("Graph client exceeds frozen storage scope")
        return create_registered_graph_writer(
            self.failed_write,
            database,
            dataset_id=dataset_id,
            neo4j_namespace=neo4j_namespace,
            authorize=self.authorize_graph,
            write_guard=self.sink_write,
        )

    @classmethod
    def acquire_for(
        cls, settings: IndexerSettings, workflow_input: dict[str, Any], info: Any
    ) -> "ManagedWriterRegistration | None":
        if not workflow_input.get("managed_document_id"):
            return None
        registration = cls(settings, workflow_input, info)
        registration.register_targets([])
        return registration

    def bind_dependencies(
        self, dependencies: IngestWorkflowDependencies
    ) -> IngestWorkflowDependencies:
        return replace(
            dependencies,
            vector_writer_factory=self.vector_writer,
            graph_writer_factory=self.graph_writer,
            checkpoint=self.check_cancelled,
        )
