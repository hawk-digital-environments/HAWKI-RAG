"""Managed operations authorize first, then resume durable ownership transitions."""

import logging
import os
import uuid
import heapq
from datetime import datetime
from pathlib import Path
from typing import BinaryIO

from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind
from hawki_backend.authorization import (
    AuthorizationAction,
    AuthorizationRequest,
    AuthorizationPort,
)
from hawki_backend.query.settings import QuerySettings
from hawki_backend.workflow_control.service import WorkflowControl
from hawki_backend.workflow_control.service import (
    DispatchUncertain,
    DispatchUnavailable,
)
from hawki_backend.workflow_control.repository import OperationConflict
from temporalio.exceptions import WorkflowAlreadyStartedError

from .artifacts import UploadStorage, hash_upload
from .cleanup import ManagedCleanup
from .input import DEFAULT_EXTENSIONS, ManagedError, ManagedInput, digest, extension
from .repository import ManagedRepository

logger = logging.getLogger(__name__)


class ManagedService:
    def __init__(
        self,
        *,
        repository: ManagedRepository,
        storage: UploadStorage,
        policy: AuthorizationPort | None,
        query_settings: QuerySettings | None,
        control: WorkflowControl | None,
        cleanup: ManagedCleanup,
        extensions: tuple[str, ...] | None = None,
    ) -> None:
        self.repository = repository
        self.storage = storage
        self.policy = policy
        self.query_settings = query_settings
        self.control = control
        self.cleanup = cleanup
        configured_extensions = (
            extensions
            if extensions is not None
            else os.environ.get(
                "RAGANYTHING_SUPPORTED_UPLOAD_EXTENSIONS", DEFAULT_EXTENSIONS
            ).split(",")
        )
        self.extensions = tuple(
            value.strip().lower().lstrip(".")
            for value in configured_extensions
            if value.strip()
        )

    def authorize(self, principal: AuthenticatedPrincipal, dataset: str) -> None:
        if self.policy is None:
            raise ManagedError(503, "Managed document authorization is unavailable.")
        if principal.kind is not PrincipalKind.USER or not self.policy.decide(
            AuthorizationRequest(
                principal=principal,
                action=AuthorizationAction.INGEST,
                dataset_id=dataset,
            )
        ):
            raise ManagedError(404, "The requested dataset is not available.")

    def can_read(self, principal: AuthenticatedPrincipal, dataset: str) -> bool:
        if self.policy is None:
            raise ManagedError(503, "Managed document authorization is unavailable.")
        return principal.kind is PrincipalKind.USER and self.policy.decide(
            AuthorizationRequest(
                principal=principal,
                action=AuthorizationAction.QUERY,
                dataset_id=dataset,
            )
        )

    def read_document(
        self, principal: AuthenticatedPrincipal, document_id: str
    ) -> dict:
        doc = self.repository.document(principal.tenant_id, document_id)
        if not self.can_read(principal, doc["dataset_id"]):
            raise ManagedError(404, "Document was not found.")
        return {"success": True, "document": self.read_payload(doc)}

    def read_payload(self, doc: dict, *, detail: bool = True) -> dict:
        # A prepared replacement is not current until publication. Initial
        # creates have only a pending revision and are still publicly visible.
        revision_id = doc["current_revision_id"] or doc["pending_revision_id"]
        revision = self.repository.revision(revision_id) if revision_id else None
        payload = self.document_payload(doc, revision, doc["attributes"])
        if not detail:
            for field in (
                "qdrant_point_count",
                "neo4j_entity_count",
                "neo4j_relation_count",
                "neo4j_live_stats",
                "markdown_preview",
                "markdown_preview_path",
                "markdown_preview_error",
                "markdown_preview_truncated",
                "related_jobs",
            ):
                payload.pop(field, None)
        return payload

    def list_documents(self, principal: AuthenticatedPrincipal, filters: dict) -> dict:
        dataset = filters.get("dataset_id") or filters.get("datasetId")
        search = (filters.get("q") or filters.get("search") or "").strip()
        grants: dict[str, bool] = {}

        def candidates():
            for doc in self.repository.read_rows(principal.tenant_id):
                if doc["status"] == "deleted" or (
                    dataset and doc["dataset_id"] != dataset
                ):
                    continue
                if doc["dataset_id"] not in grants:
                    grants[doc["dataset_id"]] = self.can_read(
                        principal, doc["dataset_id"]
                    )
                if not grants[doc["dataset_id"]]:
                    continue
                payload = self.read_payload(doc, detail=False)
                if search and not any(
                    search in str(payload.get(field) or "")
                    for field in (
                        "document_id",
                        "display_name",
                        "source_url",
                        "source_checksum_sha256",
                        "latest_source_id",
                        "latest_task_id",
                        "latest_job_id",
                    )
                ):
                    continue
                yield payload

        def order(payload):
            return tuple(
                -datetime.fromisoformat(payload[field]).timestamp()
                if payload.get(field)
                else float("inf")
                for field in ("indexed_at", "updated_at", "created_at")
            ) + (payload["document_id"],)

        return {
            "success": True,
            "documents": heapq.nsmallest(
                filters.get("limit", 100), candidates(), key=order
            ),
        }

    def legacy_read_access(
        self, principal: AuthenticatedPrincipal, candidates: list[dict]
    ) -> dict:
        owned = self.repository.owned_ids([item["document_id"] for item in candidates])
        grants = {
            dataset: self.can_read(principal, dataset)
            for dataset in {item["dataset_id"] for item in candidates}
        }
        return {
            "legacy_document_ids": [
                item["document_id"]
                for item in candidates
                if item["document_id"] not in owned and grants[item["dataset_id"]]
            ]
        }

    async def mutate(
        self,
        principal: AuthenticatedPrincipal,
        purpose: str,
        body: ManagedInput,
        filename: str | None,
        stream: BinaryIO | None,
        key: str | None,
        document_id: str | None = None,
        extension_filename: str | None = None,
        *,
        expected_generation: int | None = None,
        expected_revision_id: str | None = None,
    ) -> tuple[int, dict]:
        if purpose == "create":
            if not body.dataset_id:
                raise ManagedError(422, "The dataset_id field is required.")
            dataset = body.dataset_id
            doc = None
        else:
            doc = self.repository.document(principal.tenant_id, document_id or "")
            dataset = doc["dataset_id"]
        self.authorize(principal, dataset)
        # No filesystem publication or managed row has happened before this check.
        checksum = None
        if purpose != "delete":
            if stream is None or not filename:
                raise ManagedError(422, "Upload a readable document file.")
            checksum = hash_upload(stream)
            if body.source_checksum_sha256 and checksum != body.source_checksum_sha256:
                raise ManagedError(
                    422, "The source_checksum_sha256 does not match the uploaded file."
                )
            if (
                purpose == "update"
                and not body.force
                and body.source_updated_at is None
            ):
                raise ManagedError(422, "The source_updated_at field is required.")
        if self.control is None:
            raise ManagedError(503, "Managed workflow control is unavailable.")
        key = key.strip() if key and key.strip() else None
        if key and (
            len(key) > 255 or any(ord(char) < 32 or ord(char) == 127 for char in key)
        ):
            raise ManagedError(422, "Invalid Idempotency-Key.")
        key = key or (
            "delete:" + str(doc["generation"])
            if purpose == "delete"
            else uuid.uuid4().hex
        )
        target = document_id or "create"
        mutation_id = digest(
            [principal.tenant_id, principal.principal_id, purpose, target, key]
        )
        doc_id = document_id or "adoc_" + uuid.uuid4().hex
        canonical = body.canonical()
        fingerprint_input = body.model_dump(mode="json")
        fingerprint_input["metadata_json"] = body.metadata_json or []
        if purpose == "create":
            fingerprint_input["display_name"] = body.display_name or filename
            fingerprint_input["source_checksum_sha256"] = (
                body.source_checksum_sha256 or checksum
            )
        elif purpose == "update":
            fingerprint_input["provided"] = sorted(
                body.model_fields_set
                & {
                    "display_name",
                    "source_url",
                    "source_updated_at",
                    "metadata_json",
                    "graph",
                }
            )
        values = dict(
            mutation_id=mutation_id,
            tenant_id=principal.tenant_id,
            principal_id=principal.principal_id,
            idempotency_key=key,
            purpose=purpose,
            target=target,
            managed_document_id=doc_id,
            request_hash=digest(
                [fingerprint_input, filename, extension_filename or filename, checksum]
            ),
            request=canonical,
            stage="claimed",
        )

        def candidate(current: dict) -> tuple[dict, dict, dict]:
            if current.get("skip_reason"):
                return {}, body.attributes(filename, checksum), {}
            suffix = extension(
                extension_filename or filename,
                self.extensions,
                body.converter_mode == "custom",
            )
            source_id = "source_" + uuid.uuid4().hex
            task_id = "task_managed_" + uuid.uuid4().hex
            revision_id = uuid.uuid4().hex
            graph = (
                body.graph
                if "graph" in body.model_fields_set or purpose == "create"
                else current["attributes"].get("graph_enabled", False)
            )
            path = self.storage.destination(task_id, filename, suffix)
            attrs = current["attributes"] | body.attributes(filename, checksum)
            if purpose == "update":
                for field in (
                    "display_name",
                    "source_url",
                    "metadata_json",
                    "source_updated_at",
                ):
                    if field not in body.model_fields_set:
                        attrs[field] = current["attributes"].get(field)
            attrs["graph_enabled"] = graph
            wire, profile = self._workflow(
                dataset,
                current["managed_document_id"],
                source_id,
                task_id,
                filename,
                suffix,
                checksum,
                path,
                body,
                graph,
            )
            row = dict(
                revision_id=revision_id,
                managed_document_id=current["managed_document_id"],
                tenant_id=principal.tenant_id,
                dataset_id=dataset,
                generation=current["next_generation"],
                expected_generation=current["generation"],
                mutation_id=mutation_id,
                source_checksum=checksum,
                upload_path=path,
                source_id=source_id,
                task_id=task_id,
                job_id=wire["job_id"],
                workflow_id="ingest-source-" + source_id,
                status="prepared",
                workflow_input=wire,
                writer_state={
                    "managed_writer_protocol": 1,
                    "managed_writer_gate": "open",
                    "managed_writers": {},
                    "managed_targets": {},
                    "neo4j_database": os.environ.get("NEO4J_DATABASE") or None,
                },
            )
            return (
                row,
                attrs,
                {
                    "state": "prepared",
                    "upload_path": path,
                    "checksum": checksum,
                    "task_id": task_id,
                    "filename": filename,
                    "extension": suffix,
                    "source_id": source_id,
                    "profile": profile,
                    "profile_path": wire.get("custom_converter_profile_path"),
                },
            )

        def skip(current: dict) -> str | None:
            if body.force or current["status"] == "deleted":
                return None
            attrs = current["attributes"]
            previous = attrs.get("source_updated_at")
            if previous and body.source_updated_at:
                if body.source_updated_at <= datetime.fromisoformat(previous):
                    return "incoming source_updated_at is not newer than the stored document"
                return None
            if body.source_checksum_sha256 and body.source_checksum_sha256 == attrs.get(
                "source_checksum_sha256"
            ):
                return "incoming source_checksum_sha256 matches the stored document"
            return None

        try:
            operation = self.repository.claim(
                values,
                candidate if purpose != "delete" else None,
                skip if purpose == "update" else None,
                expected_generation=expected_generation,
                expected_revision_id=expected_revision_id,
            )
            if (
                operation["request"].get("artifact")
                and operation["request"]["artifact"]["state"] != "published"
            ):
                try:
                    # Share the existing session lock with cleanup: crashed
                    # publishers release it; delayed copies cannot overlap retry.
                    with self.repository.cleanup_owner(operation):
                        operation = self.repository.mutation(operation["mutation_id"])
                        artifact = operation["request"]["artifact"]
                        if artifact["state"] != "published":
                            # Reject config drift before touching any new path.
                            if self.storage.destination(
                                artifact["task_id"],
                                artifact["filename"],
                                artifact["extension"],
                            ) != artifact["upload_path"] or (
                                artifact["profile"] is not None
                                and self.storage.profile_path(artifact["source_id"])
                                != artifact["profile_path"]
                            ):
                                raise ManagedError(
                                    502,
                                    "Durable upload destination changed; restore its prepared storage configuration.",
                                )
                            stored = self.storage.store(
                                artifact["task_id"],
                                artifact["filename"],
                                artifact["extension"],
                                stream,
                                artifact["checksum"],
                            )
                            if stored != artifact["upload_path"]:
                                raise ManagedError(
                                    502, "Durable upload destination changed."
                                )
                            if artifact["profile"] is not None:
                                self.storage.profile(
                                    artifact["source_id"], artifact["profile"]
                                )
                            self.repository.mark_artifact_published(
                                operation["mutation_id"]
                            )
                        operation = self.repository.mutation(operation["mutation_id"])
                except ManagedError as error:
                    if error.status == 202:
                        return self.result(operation, 202, "pending")
                    raise
                except Exception:
                    return self.result(
                        operation,
                        502,
                        "failed",
                        "Artifact publication is unconfirmed; retry the same request and Idempotency-Key.",
                    )
            return await self.resume(operation)
        except OSError:
            raise ManagedError(
                500,
                "The uploaded file could not be stored. No dataset, task, or job was created.",
                {"dataset_id": dataset, "task_id": None, "job_id": None, "error": None},
            ) from None

    def _workflow(
        self,
        dataset: str,
        doc_id: str,
        source: str,
        task: str,
        filename: str,
        suffix: str,
        checksum: str,
        path: str,
        body: ManagedInput,
        graph: bool,
    ) -> tuple[dict, dict | None]:
        scope = (
            self.query_settings.resolve_scope(dataset) if self.query_settings else None
        )
        models = (
            self.query_settings.models_for(scope.embedding_provider) if scope else None
        )
        if not scope or not models or (graph and not scope.neo4j_namespace):
            raise ManagedError(
                409, "The requested dataset has no managed ingestion scope."
            )
        base = self.storage.root / "sources" / source
        wire = {
            "source_id": source,
            "source_url": "upload://" + filename,
            "dataset_id": dataset,
            "managed_document_id": doc_id,
            "managed_writer_protocol": 1,
            "task_id": task,
            "job_id": "job_" + digest([task, source])[:32],
            "upload": {
                "original_filename": filename,
                "target_name": Path(path).name,
                "local_path": path,
                "content_hash": checksum,
                "extension": suffix,
            },
            "converter_mode": body.converter_mode,
            "raw_output_path": str(base / "raw") + "/",
            "markdown_output_path": str(base / "markdown") + "/",
            "ingest_manifest_path": str(base / "ingest" / "manifest.json"),
            "storage": {"shared_root": str(self.storage.root)},
            "refresh": {"content_hash": checksum},
            "ingestion": {
                "provider": scope.embedding_provider,
                "embedding_model": scope.embedding_model,
                "graph_model": models.chat_model,
                "vision_model": models.vision_model,
                "graph": graph,
                "collection": scope.qdrant_collection,
                "neo4j_namespace": scope.neo4j_namespace,
                "neo4j_database": os.environ.get("NEO4J_DATABASE", "neo4j")
                if scope.neo4j_namespace
                else None,
                "chunk_chars": int(os.environ.get("CHUNK_SIZE", "1200")),
                "chunk_overlap": int(os.environ.get("CHUNK_OVERLAP_SIZE", "250")),
                "batch_size": int(os.environ.get("INGEST_BATCH_SIZE", "64")),
            },
        }
        profile = None
        if body.converter_mode == "custom":
            if not body.converter_url:
                raise ManagedError(
                    422,
                    "The converter url field is required when converter mode is custom.",
                )
            profile = {
                "version": "1",
                "source_id": source,
                "converter_status_path": os.environ.get("CUSTOM_CONVERTER_STATUS_PATH")
                or os.environ.get("EXTERNAL_CONVERTER_STATUS_PATH")
                or "/jobs/{job_id}",
                "converter_url": body.converter_url,
                "converter_start_path": "/" + body.converter_start_path.lstrip("/"),
            }
            if body.converter_token:
                profile["converter_token"] = body.converter_token
            wire["custom_converter_profile_path"] = self.storage.profile_path(source)
        # Construct and validate all worker input before committing the intent;
        # this method never publishes uploads or converter credentials.
        return self.control.trusted_input("source", wire), profile

    async def resume(self, operation: dict) -> tuple[int, dict]:
        if (
            operation["receipt"]
            and "payload" in operation["receipt"]
            and operation["stage"] in {"completed", "skipped"}
        ):
            return operation["receipt"]["status"], operation["receipt"]["payload"]
        if operation["stage"] in {"fenced", "drained", "cleaned"}:
            try:
                with self.repository.cleanup_owner(operation):
                    operation = self.repository.mutation(operation["mutation_id"])
                    if operation["stage"] in {"fenced", "drained"}:
                        owners = self.repository.cleanup_snapshot(
                            operation, drained=False
                        )
                        for revision in owners:
                            if revision["run_id"] and revision["status"] not in {
                                "ready",
                                "cleaned",
                            }:
                                await self.control.port.cancel_workflow_and_wait(
                                    workflow_id=revision["workflow_id"],
                                    run_id=revision["run_id"],
                                )
                                self.repository.acknowledge_closed_without_writers(
                                    operation, revision["revision_id"]
                                )
                        owners = self.repository.cleanup_snapshot(operation)
                        self.repository.advance(operation, "drained")
                        for revision in owners:
                            self.cleanup.delete(revision, operation["mutation_id"])
                        self.repository.advance(operation, "cleaned")
                    if operation["stage"] not in {
                        "prepared",
                        "dispatched",
                        "completed",
                    }:
                        operation = self.repository.prepare_successor(operation)
            except ManagedError as error:
                if error.status != 202:
                    raise
                return self.result(operation, 202, "pending")
            except Exception as error:
                logger.warning(
                    "Managed cleanup unconfirmed operation_id=%s exception_class=%s",
                    operation["mutation_id"],
                    type(error).__name__,
                )
                return self.result(
                    operation,
                    502,
                    "failed",
                    "Managed cleanup could not be verified; retry the same request.",
                )
        if operation["stage"] in {"prepared", "dispatched"}:
            revision = self.repository.revision(operation["revision_id"])
            try:
                with Path(revision["upload_path"]).open("rb") as stream:
                    if hash_upload(stream) != revision["source_checksum"]:
                        raise ManagedError(502, "Durable replacement bytes changed.")
                receipt = await self.control.start(
                    "source",
                    revision["workflow_id"],
                    revision["workflow_input"],
                    replacement=operation["purpose"] == "update",
                )
                self.repository.record_dispatch(operation, receipt["run_id"])
            except (
                DispatchUncertain,
                DispatchUnavailable,
                OperationConflict,
                WorkflowAlreadyStartedError,
                OSError,
            ):
                return self.result(
                    operation,
                    502,
                    "failed",
                    "Workflow startup is unconfirmed; retry the same request and Idempotency-Key.",
                )
            return self.result(operation, 202, "accepted")
        if operation["stage"] == "skipped":
            return self.result(
                operation,
                200,
                "skipped_unchanged",
                reason=operation["receipt"]["reason"],
            )
        return self.result(
            operation,
            200 if operation["purpose"] == "delete" else 202,
            "completed" if operation["purpose"] == "delete" else "accepted",
        )

    def result(
        self,
        operation: dict,
        status: int,
        state: str,
        error: str | None = None,
        reason: str | None = None,
    ) -> tuple[int, dict]:
        doc = self.repository.document(
            operation["tenant_id"], operation["managed_document_id"]
        )
        if operation["request"].get("deleted_document"):
            doc = operation["request"]["deleted_document"]
        elif operation["stage"] == "skipped" and operation["receipt"].get(
            "document_snapshot"
        ):
            doc = operation["receipt"]["document_snapshot"]
        revision = (
            self.repository.revision(
                operation["revision_id"]
                or doc["pending_revision_id"]
                or doc["current_revision_id"]
            )
            if (
                operation["revision_id"]
                or doc["pending_revision_id"]
                or doc["current_revision_id"]
            )
            else None
        )
        attrs = doc["attributes"]
        if (
            revision
            and operation["purpose"] != "delete"
            and state != "skipped_unchanged"
        ):
            attrs = attrs | operation["request"].get("attributes", {})
        payload = {
            "success": error is None,
            "operation": {"type": operation["purpose"], "status": state},
            "document": self.document_payload(doc, revision, attrs),
        }
        if reason:
            payload["operation"]["reason"] = reason
        if error:
            payload.update(message=error, error=error)
        if operation["purpose"] == "delete" or state == "pending":
            payload["operation"].update(
                operation_id=operation["mutation_id"],
                workflow_id=revision["workflow_id"] if revision else None,
                run_id=revision["run_id"] if revision else None,
                recovery="Repeat DELETE to resume the same operation."
                if operation["purpose"] == "delete"
                else "Resubmit the same PUT, file and Idempotency-Key to resume replacement.",
            )
            payload.update(
                deletion={
                    "bridge_documents_deleted": sum(
                        len(
                            self.repository.revision(entry["revision_id"])[
                                "writer_state"
                            ].get("managed_targets", {})
                        )
                        for entry in operation["cleanup_snapshot"]
                    )
                    if state == "completed"
                    else 0,
                    "results": [],
                },
                error=error,
            )
        elif state == "accepted":
            payload["pipeline"] = {
                key: revision[key] for key in ("task_id", "job_id", "source_id")
            }
        if (status == 200 and state in {"completed", "skipped_unchanged"}) or (
            status == 202 and state == "accepted"
        ):
            self.repository.save_receipt(operation["mutation_id"], status, payload)
        return status, payload

    @staticmethod
    def document_payload(doc: dict, revision: dict | None, attrs: dict) -> dict:
        scope = revision["workflow_input"]["ingestion"] if revision else {}
        completion = (revision.get("completion") or {}) if revision else {}
        published = bool(
            revision
            and revision["status"] == "ready"
            and doc["status"] == "indexed"
            and doc["current_revision_id"] == revision["revision_id"]
        )
        indexed_at = completion.get("occurred_at") if published else None
        targets = (
            revision["writer_state"].get("managed_targets", {})
            if revision and published
            else {}
        )
        chunks = (
            completion.get("metrics", {}).get("chunks_indexed", 0) if published else 0
        )
        outputs = [
            {
                "bridge_document_id": target_id,
                "qdrant_collection": scope.get("collection"),
                "neo4j_namespace": scope.get("neo4j_namespace")
                if target["graph"]
                else None,
                "chunk_count": chunks if len(targets) == 1 else 0,
                "status": "indexed",
                "active": True,
                "indexed_at": indexed_at,
            }
            for target_id, target in targets.items()
        ]
        return {
            "id": doc["managed_document_id"],
            "document_id": doc["managed_document_id"],
            "dataset_id": doc["dataset_id"],
            "status": doc["status"],
            "source_type": "upload",
            **attrs,
            "title": attrs.get("display_name") or doc["managed_document_id"],
            "content_hash": attrs.get("source_checksum_sha256"),
            "metadata": attrs.get("metadata_json", []),
            "latest_source_id": revision["source_id"] if revision else None,
            "latest_task_id": revision["task_id"] if revision else None,
            "latest_job_id": revision["job_id"] if revision else None,
            "latest_document_version": completion.get("document_version")
            if published
            else None,
            "last_error": "Managed ingestion failed."
            if doc["status"] == "failed"
            else None,
            "qdrant_collection": scope.get("collection"),
            "collection": scope.get("collection"),
            "neo4j_namespace": scope.get("neo4j_namespace"),
            "qdrant_status": doc["status"],
            "neo4j_status": doc["status"] if attrs.get("graph_enabled") else "disabled",
            "outputs": outputs,
            "active_output_count": len(outputs),
            "active_chunk_count": chunks,
            "created_at": datetime.fromisoformat(str(doc["created_at"])).isoformat(),
            "updated_at": datetime.fromisoformat(str(doc["updated_at"])).isoformat(),
            "indexed_at": indexed_at,
            "ingested_at": indexed_at,
            "content_type": None,
            "local_path": None,
            "file_size": None,
            "qdrant_point_count": chunks,
            "neo4j_entity_count": None,
            "neo4j_relation_count": None,
            "neo4j_live_stats": None,
            "markdown_preview": "",
            "markdown_preview_path": None,
            "markdown_preview_error": "No extracted Markdown preview is available.",
            "markdown_preview_truncated": False,
            "related_jobs": [],
        }
