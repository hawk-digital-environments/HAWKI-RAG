"""Authorize, freeze URL intent and resume fenced generation transitions."""

import uuid
import hashlib
from pathlib import Path

from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind
from hawki_backend.authorization import (
    AuthorizationAction,
    AuthorizationRequest,
    AuthorizationPort,
)
from hawki_backend.managed_documents.cleanup import ManagedCleanup
from hawki_backend.query.settings import QuerySettings
from hawki_backend.workflow_control.identity import source_retry, source_workflow
from hawki_backend.workflow_control.repository import OperationConflict
from hawki_backend.workflow_control.service import (
    WorkflowControl,
    DispatchUncertain,
    DispatchUnavailable,
)

from .errors import UrlIngestionError
from .identity import digest
from .repository import UrlRepository
from .settings import UrlIngestionSettings
from .validation import (
    UrlStartInput,
    UrlSafetyValidator,
    ExternalCrawlerBoundary,
    site_profile,
)


class UrlIngestionService:
    def __init__(
        self,
        *,
        repository: UrlRepository,
        policy: AuthorizationPort | None,
        query_settings: QuerySettings | None,
        control: WorkflowControl | None,
        settings: UrlIngestionSettings,
        cleanup: ManagedCleanup,
        safety: UrlSafetyValidator | None = None,
        crawler: ExternalCrawlerBoundary | None = None,
        neo4j_database: str | None = None,
        quiescence=None,
    ) -> None:
        self.repository = repository
        self.policy = policy
        self.query_settings = query_settings
        self.control = control
        self.settings = settings
        self.cleanup = cleanup
        self.safety = safety or UrlSafetyValidator()
        self.crawler = crawler or ExternalCrawlerBoundary()
        self.neo4j_database = neo4j_database
        from .quiescence import ExternalJobQuiescence

        self.quiescence = quiescence or ExternalJobQuiescence(repository)

    def authorize(self, principal: AuthenticatedPrincipal, dataset: str) -> None:
        if self.policy is None:
            raise UrlIngestionError(503, "URL authorization is unavailable.")
        if principal.kind is not PrincipalKind.USER or not self.policy.decide(
            AuthorizationRequest(
                principal=principal,
                action=AuthorizationAction.INGEST,
                dataset_id=dataset,
            )
        ):
            raise UrlIngestionError(404, "The requested dataset is not available.")

    async def start(
        self,
        principal: AuthenticatedPrincipal,
        body: UrlStartInput,
        key: str | None,
        *,
        expected_generations: dict[str, int] | None = None,
        scheduled: bool = False,
    ) -> tuple[int, dict]:
        self.authorize(principal, body.dataset_id)
        if (
            not self.settings.enabled
            or not self.settings.scraper_url
            or self.control is None
        ):
            raise UrlIngestionError(503, "URL ingestion is not configured.")
        # Sitemap expansion must never fall back to Laravel's HTTP/filesystem
        # resolver. Both kinds remain blocked until the same safe fetch boundary
        # can protect redirects and all discovered crawl resources.
        if body.sitemap_url:
            self.safety.validate(body.sitemap_url)
            raise UrlIngestionError(
                503,
                "Sitemap ingestion is blocked until safe fetch enforcement is available.",
            )
        if body.sitemap_path:
            raise UrlIngestionError(
                422, "Local sitemap paths are not supported in Python mode."
            )
        profile = site_profile(
            body.metadata.get("site_profile_path"), self.settings.profile_root
        )
        urls = body.requested_urls()
        if not urls:
            raise UrlIngestionError(422, "Supply at least one source URL.")
        validated = [self.safety.validate(url) for url in urls]
        # A DNS policy check is evidence, not a capability to prevent rebinding.
        self.crawler.require_safe_fetch(validated)
        canonical = body.model_dump(mode="json")
        if scheduled:
            canonical["_scheduled"] = True
        canonical["urls"] = list(dict.fromkeys(value.identity for value in validated))
        canonical["source_url"] = None
        metadata = dict(body.metadata)
        if profile:
            metadata["site_profile_path"] = profile
        profile_hash = None
        if profile:
            with Path(profile).open("rb") as stream:
                profile_bytes = stream.read(2 * 1024 * 1024 + 1)
            if len(profile_bytes) > 2 * 1024 * 1024:
                raise UrlIngestionError(422, "The approved site profile is too large.")
            profile_hash = hashlib.sha256(profile_bytes).hexdigest()
        configuration = {
            "mode": "profile" if profile else "crawl",
            "identity": digest([self.settings.scraper_url, metadata, profile_hash]),
            "profile_sha256": profile_hash,
        }
        values = self._request(principal, canonical, key, "start", body.task_id)

        def candidate(source: dict, request: dict) -> dict:
            return self._candidate(source, request, metadata, configuration)

        # Preserve the first original spelling, while deduplicating only exact
        # legacy normalized identities inside this multi-URL task.
        unique = {}
        for original, value in zip(urls, validated, strict=True):
            unique.setdefault(value.identity, original)
        task, children = self.repository.claim(
            principal,
            values,
            [(original, normalized) for normalized, original in unique.items()],
            candidate,
            expected_generations=expected_generations,
            scheduled=scheduled,
        )
        for operation in children:
            await self.resume(operation)
        return 201, self.task_payload(principal, task["task_id"])

    def _request(
        self,
        principal: AuthenticatedPrincipal,
        body: dict,
        key: str | None,
        purpose: str,
        task: str | None = None,
    ) -> dict:
        key = key.strip() if key else uuid.uuid4().hex
        if (
            not key
            or len(key) > 255
            or any(ord(char) < 32 or ord(char) == 127 for char in key)
        ):
            raise UrlIngestionError(422, "Invalid Idempotency-Key.")
        return dict(
            request_id=digest([principal.tenant_id, principal.principal_id, key]),
            tenant_id=principal.tenant_id,
            principal_id=principal.principal_id,
            dataset_id=body["dataset_id"],
            idempotency_key=key,
            request_hash=digest([purpose, body]),
            purpose=purpose,
            task_id=task or "task_" + uuid.uuid4().hex,
            body=body,
        )

    def _candidate(
        self,
        source: dict,
        request: dict,
        metadata: dict,
        configuration: dict | None = None,
    ) -> dict:
        generation = source["generation"] + 1
        source_id = source["source_id"]
        operation_id = digest([request["request_id"], source_id])
        job = "ingest_" + digest([request["task_id"], source_id])[:24]
        row = dict(
            url_ingestion_operation_id=operation_id,
            request_id=request["request_id"],
            tenant_id=request["tenant_id"],
            dataset_id=source["dataset_id"],
            source_id=source_id,
            generation=generation,
            expected_generation=source["generation"],
            task_id=request["task_id"],
            job_id=job,
            purpose=request["purpose"],
            stage="fenced",
            workflow_input={},
            writer_state={
                "managed_writer_protocol": 1,
                "managed_writer_gate": "open",
                "managed_writers": {},
                "managed_targets": {},
            },
            external_job={
                "job_id": job,
                "lifecycle": "reserved",
                "cancel_requested_at": None,
                "quiescent": False,
            },
            schedule_state="absent",
            content_identity={"version": 1, "status": "unknown"},
            retired=False,
        )
        if request["purpose"] == "delete":
            return row
        scope = (
            self.query_settings.resolve_scope(source["dataset_id"])
            if self.query_settings
            else None
        )
        models = (
            self.query_settings.models_for(scope.embedding_provider) if scope else None
        )
        graph = metadata.get("graph", False)
        if not isinstance(graph, (bool, str, int)):
            raise UrlIngestionError(422, "Invalid graph option.")
        if not isinstance(graph, bool):
            if graph in {"true", "1", 1}:
                graph = True
            elif graph in {"false", "0", 0}:
                graph = False
            else:
                raise UrlIngestionError(422, "Invalid graph option.")
        if not scope or not models or (graph and not scope.neo4j_namespace):
            raise UrlIngestionError(409, "The dataset has no URL ingestion scope.")
        base = Path(self.settings.shared_root) / "sources" / source_id
        wire = dict(
            owner="url",
            url_ingestion_operation_id=operation_id,
            generation=generation,
            source_id=source_id,
            source_url=source["normalized_url"],
            dataset_id=source["dataset_id"],
            task_id=request["task_id"],
            job_id=job,
            managed_writer_protocol=1,
            raw_output_path=str(base / "raw") + "/",
            markdown_output_path=str(base / "markdown") + "/",
            ingest_manifest_path=str(base / "ingest" / "manifest.json"),
            storage={"shared_root": self.settings.shared_root},
            metadata={
                "request": {"metadata": metadata},
                "scraper_configuration": configuration or {},
            },
            external_services={
                "scraper_url": self.settings.scraper_url,
                "scraper_token": self.settings.scraper_token,
            },
            ingestion=dict(
                provider=scope.embedding_provider,
                embedding_model=scope.embedding_model,
                graph_model=models.chat_model,
                vision_model=models.vision_model,
                graph=graph,
                collection=scope.qdrant_collection,
                neo4j_namespace=scope.neo4j_namespace,
                neo4j_database=self.neo4j_database,
            ),
        )
        row["workflow_input"] = self.control.trusted_input("source", wire)
        row["workflow_id"] = (
            source_workflow(source_id)
            if generation == 1
            else source_retry(source_id, None, generation - 1, job)
        )
        # The URL string is never labeled as a content hash. The existing
        # manifest/artifact path stays unchanged across recrawl generations.
        return row

    async def resume(self, operation: dict) -> None:
        if operation["stage"] == "completed":
            return
        if self.control is None:
            raise UrlIngestionError(503, "URL workflow control is unavailable.")
        try:
            with self.repository.execution_owner(operation):
                operation = self.repository.generation(
                    operation["url_ingestion_operation_id"]
                )
                if operation["stage"] in {"fenced", "drained"}:
                    owners = self.repository.snapshot(operation, drained=False)
                    for old in owners:
                        run_id = old["run_id"]
                        if run_id is None:
                            # A delayed uncertain start remains the same durable
                            # operation. NOT_FOUND is never a drainage proof.
                            receipt = await self.control.start(
                                "source", old["workflow_id"], old["workflow_input"]
                            )
                            self.repository.dispatched(old, receipt)
                            run_id = receipt["run_id"]
                        await self.control.port.cancel_workflow_and_wait(
                            workflow_id=old["workflow_id"], run_id=run_id
                        )
                        self.repository.closed_empty(
                            old["url_ingestion_operation_id"], run_id
                        )
                    # Workflow closure cannot prove that a remote writer stopped.
                    # No sink cleanup, schedule mutation, or artifact unlink is
                    # permitted until every old remote job is proven quiescent.
                    for old in owners:
                        self.quiescence.ensure(old)
                    self.repository.snapshot(operation, drained=True)
                    operation = self.repository.advance(operation, "drained")
                    for old in owners:
                        self.cleanup.delete(
                            old, operation["url_ingestion_operation_id"]
                        )
                        if old["schedule_id"]:
                            await self.control.port.delete_schedule(
                                schedule_id=old["schedule_id"]
                            )
                        self.cleanup_artifacts(old)
                    operation = self.repository.advance(operation, "cleaned")
                if operation["stage"] == "cleaned":
                    operation = self.repository.advance(
                        operation,
                        "completed" if operation["purpose"] == "delete" else "prepared",
                    )
                if operation["stage"] in {"prepared", "dispatched"}:
                    receipt = await self.control.start(
                        "source", operation["workflow_id"], operation["workflow_input"]
                    )
                    self.repository.dispatched(operation, receipt)
        except UrlIngestionError:
            raise
        except (DispatchUncertain, DispatchUnavailable, OperationConflict):
            raise UrlIngestionError(
                503, "URL dispatch is unconfirmed; retry the same Idempotency-Key."
            ) from None

    def task_payload(self, principal: AuthenticatedPrincipal, task_id: str) -> dict:
        task, children = self.repository.task(principal.tenant_id, task_id)
        self.authorize(principal, task["dataset_id"])
        jobs = [
            dict(
                job_id=row["job_id"],
                source_id=row["source_id"],
                url_ingestion_operation_id=row["url_ingestion_operation_id"],
                generation=row["generation"],
                temporal_workflow_id=row.get("workflow_id"),
                status=(
                    "failed"
                    if (row.get("completion") or {}).get("status")
                    in {"failed", "skipped"}
                    else "completed"
                    if row["stage"] == "completed"
                    else "running"
                ),
                task_id=row["task_id"],
                job_type="scrape",
                content_hash=None,
                temporal_run_id=row.get("run_id"),
                temporal_schedule_id=row.get("schedule_id"),
                index_status=(
                    "ready"
                    if (row.get("completion") or {}).get("status") == "completed"
                    else None
                ),
                source_url=row["workflow_input"].get("source_url"),
            )
            for row in children
        ]
        from .reads import stage_projection

        stages = stage_projection(self, children)
        return dict(
            success=True,
            task_id=task_id,
            task=dict(
                task_id=task_id,
                dataset_id=task["dataset_id"],
                metadata={
                    "request": {
                        key: value
                        for key, value in task["body"].items()
                        if key != "_scheduled"
                    }
                },
                jobs=jobs,
                total_jobs=len(jobs),
                status=(
                    "running"
                    if any(job["status"] == "running" for job in jobs)
                    else "failed"
                    if any(job["status"] == "failed" for job in jobs)
                    else "completed"
                ),
                active_jobs=sum(job["status"] == "running" for job in jobs),
                started_at=task["created_at"].isoformat(),
                updated_at=task["updated_at"].isoformat(),
                finished_at=None,
                counters={
                    "jobs_total": len(jobs),
                    "jobs_running": sum(job["status"] == "running" for job in jobs),
                    "jobs_active": sum(job["status"] == "running" for job in jobs),
                    "jobs_completed": sum(job["status"] == "completed" for job in jobs),
                    "jobs_failed": sum(job["status"] == "failed" for job in jobs),
                    "jobs_skipped": 0,
                    "total": len(jobs),
                    "completed": sum(job["status"] == "completed" for job in jobs),
                    "failed": sum(job["status"] == "failed" for job in jobs),
                },
                managed_documents=[],
                managed_document_count=0,
                stages=stages,
                sources=[
                    {
                        "source_id": job["source_id"],
                        "source_url": job["source_url"],
                        "dataset_id": task["dataset_id"],
                        "index_status": job["index_status"],
                    }
                    for job in jobs
                ],
            ),
        )

    @staticmethod
    def cleanup_artifacts(old: dict) -> None:
        """Remove only the frozen source tree, after external-job quiescence."""
        import shutil

        wire = old["workflow_input"]
        root = Path(wire["storage"]["shared_root"]).resolve()
        expected = root / "sources" / old["source_id"]
        if expected.resolve() != expected or not expected.is_relative_to(root):
            raise UrlIngestionError(409, "Frozen source artifact ownership differs.")
        for field, relative in (
            ("raw_output_path", "raw"),
            ("markdown_output_path", "markdown"),
            ("ingest_manifest_path", "ingest/manifest.json"),
        ):
            if Path(wire[field]) != expected / relative:
                raise UrlIngestionError(409, "Frozen artifact layout differs.")
        if expected.exists():
            shutil.rmtree(expected)
        if expected.exists():
            raise UrlIngestionError(503, "Artifact deletion was not verified.")

    async def delete(
        self, principal: AuthenticatedPrincipal, source_id: str, key: str | None
    ) -> tuple[int, dict]:
        source = self.repository.source(principal.tenant_id, source_id)
        self.authorize(principal, source["dataset_id"])
        # A headerless retry must resolve the delete that already owns the
        # source. Claiming deletion itself advances the generation counter.
        delete_generation = source["generation"]
        if source["status"] in {"deleting", "deleted"}:
            delete_generation -= 1
        values = self._request(
            principal,
            {"dataset_id": source["dataset_id"], "source_id": source_id},
            key or "delete:" + source_id + ":" + str(delete_generation),
            "delete",
        )
        task, children = self.repository.claim(
            principal,
            values,
            [(source["original_url"], source["normalized_url"])],
            lambda row, request: self._candidate(row, request, {}),
            expected_generations={source_id: source["generation"]},
        )
        for operation in children:
            await self.resume(operation)
        return 200, self.task_payload(principal, task["task_id"])

    async def recrawl(
        self,
        principal: AuthenticatedPrincipal,
        source_id: str,
        body: dict,
        key: str | None,
        *,
        expected_generation: int | None = None,
        scheduled: bool = False,
    ) -> tuple[int, dict]:
        source = self.repository.source(principal.tenant_id, source_id)
        self.authorize(principal, source["dataset_id"])
        with self.repository.engine.connect() as connection:
            import sqlalchemy as sa
            from .schema import requests, generations

            original = (
                connection.execute(
                    sa.select(requests.c.body)
                    .join(
                        generations, generations.c.request_id == requests.c.request_id
                    )
                    .where(
                        generations.c.source_id == source_id,
                        generations.c.generation == source["generation"],
                    )
                ).scalar_one_or_none()
                or {}
            )
        body = {
            "metadata": original.get("metadata", {}),
            "refresh_cadence": source.get("refresh_cadence"),
            **body,
        }
        return await self.start(
            principal,
            UrlStartInput.model_validate(
                body
                | {
                    "dataset_id": source["dataset_id"],
                    "source_url": source["normalized_url"],
                    "urls": None,
                }
            ),
            key,
            scheduled=scheduled,
            expected_generations={
                source_id: source["generation"]
                if expected_generation is None
                else expected_generation
            },
        )

    async def recovery(
        self, principal: AuthenticatedPrincipal, path: str, body: dict, key: str | None
    ) -> tuple[int, dict]:
        from .recovery import recover

        return await recover(self, principal, path, body, key)
