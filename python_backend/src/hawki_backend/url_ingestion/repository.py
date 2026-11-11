"""PostgreSQL locks bind grants, generations, callbacks and writer fencing."""

import copy
from collections.abc import Callable, Iterator
from contextlib import contextmanager
from datetime import datetime, timezone

import sqlalchemy as sa
from sqlalchemy.engine import Connection, Engine

from hawki_backend.auth.models import AuthenticatedPrincipal
from hawki_backend.persistence.datasets import (
    lock_active_dataset,
    lock_dataset_for_cleanup,
)
from hawki_backend.persistence.schema import datasets, dataset_grants
from hawki_backend.text_ingestion.writers import (
    WriterConflict,
    WriterEvent,
    update_writer,
    writers_released,
)
from hawki_backend.workflow_control.schema import operations

from .errors import UrlIngestionError
from .cadence import next_refresh
from .artifacts import verify_manifest
from .identity import digest, source_identity
from .locking import lock, lock_key, one
from .schema import events, generations, requests, sources


def require_grant(
    connection: Connection, principal: AuthenticatedPrincipal, dataset: str
) -> None:
    # Hold both rows through the mutation transaction. Revocation/archive cannot
    # race between an earlier policy decision and allocation of URL authority.
    grant = connection.execute(
        sa.select(dataset_grants.c.principal_id)
        .select_from(
            dataset_grants.join(
                datasets,
                (datasets.c.tenant_id == dataset_grants.c.tenant_id)
                & (datasets.c.dataset_id == dataset_grants.c.dataset_id),
            )
        )
        .where(
            datasets.c.tenant_id == principal.tenant_id,
            datasets.c.dataset_id == dataset,
            datasets.c.status == "active",
            dataset_grants.c.principal_type == "user",
            dataset_grants.c.principal_id == principal.principal_id,
            dataset_grants.c.permission == "ingest",
        )
        .with_for_update()
    ).first()
    if grant is None:
        raise UrlIngestionError(404, "The requested dataset is not available.")


class UrlRepository:
    def __init__(self, engine: Engine) -> None:
        self.engine = engine

    def source(self, tenant: str, source_id: str) -> dict:
        with self.engine.connect() as connection:
            row = one(
                connection,
                sources,
                sources.c.tenant_id == tenant,
                sources.c.source_id == source_id,
            )
        if row is None:
            raise UrlIngestionError(404, "URL source was not found.")
        return row

    def list_blocking_sources(self, tenant: str, dataset_id: str) -> list[str]:
        """Return source ids of one dataset that are not deleted yet."""

        with self.engine.connect() as connection:
            return list(
                connection.execute(
                    sa.select(sources.c.source_id)
                    .where(
                        sources.c.tenant_id == tenant,
                        sources.c.dataset_id == dataset_id,
                        sources.c.status != "deleted",
                    )
                    .order_by(sources.c.source_id)
                ).scalars()
            )

    def generation(self, operation_id: str) -> dict:
        with self.engine.connect() as connection:
            row = one(
                connection,
                generations,
                generations.c.url_ingestion_operation_id == operation_id,
            )
        if row is None:
            raise UrlIngestionError(404, "URL operation was not found.")
        return row

    def task(self, tenant: str, task_id: str) -> tuple[dict, list[dict]]:
        with self.engine.connect() as connection:
            row = one(
                connection,
                requests,
                requests.c.tenant_id == tenant,
                requests.c.task_id == task_id,
            )
            if row is None:
                raise UrlIngestionError(404, "URL task was not found.")
            children = list(
                connection.execute(
                    sa.select(generations)
                    .where(generations.c.request_id == row["request_id"])
                    .order_by(generations.c.source_id)
                ).mappings()
            )
        return row, [dict(child) for child in children]

    def claim(
        self,
        principal: AuthenticatedPrincipal,
        values: dict,
        urls: list[tuple[str, str]],
        build: Callable[[dict, dict], dict],
        *,
        expected_generations: dict[str, int] | None = None,
        scheduled: bool = False,
        internal: bool = False,
    ) -> tuple[dict, list[dict]]:
        with self.engine.begin() as connection:
            # The dataset row is the admission fence for URL authority too.
            # Internal cleanup may act on an archived dataset but still locks
            # the same row, so it cannot race finalization.
            if internal:
                lock_dataset_for_cleanup(
                    connection, principal.tenant_id, values["dataset_id"]
                )
            else:
                lock_active_dataset(
                    connection, principal.tenant_id, values["dataset_id"]
                )
                require_grant(connection, principal, values["dataset_id"])
            lock(connection, "url-request:" + values["request_id"])
            previous = one(
                connection, requests, requests.c.request_id == values["request_id"]
            )
            if previous:
                if previous["request_hash"] != values["request_hash"]:
                    raise UrlIngestionError(
                        409, "The idempotency key was reused with a different request."
                    )
                return previous, [
                    dict(row)
                    for row in connection.execute(
                        sa.select(generations).where(
                            generations.c.request_id == previous["request_id"]
                        )
                    ).mappings()
                ]
            # Prevent supplied task IDs from aliasing another principal/request.
            lock(connection, "url-task:" + values["task_id"])
            if one(connection, requests, requests.c.task_id == values["task_id"]):
                raise UrlIngestionError(409, "Task identity already exists.")
            connection.execute(requests.insert().values(**values))
            prepared = []
            identities = sorted(
                (
                    source_identity(principal.tenant_id, values["dataset_id"], url),
                    original,
                    url,
                )
                for original, url in urls
            )
            for source_id, original, url in identities:
                # The same key is also held as a session lock across dispatch
                # and cleanup, so a delayed dispatcher cannot cross a fence.
                lock(connection, "url-source:" + source_id)
                source = one(connection, sources, sources.c.source_id == source_id)
                if source is None:
                    if values["purpose"] == "delete":
                        raise UrlIngestionError(404, "URL source was not found.")
                    source = dict(
                        source_id=source_id,
                        tenant_id=principal.tenant_id,
                        principal_id=principal.principal_id,
                        dataset_id=values["dataset_id"],
                        original_url=original,
                        normalized_url=url,
                        identity_hash=digest(url),
                        generation=0,
                        current_generation=None,
                        status="pending",
                    )
                    connection.execute(sources.insert().values(**source))
                if (
                    source["normalized_url"] != url
                    or source["tenant_id"] != principal.tenant_id
                ):
                    raise UrlIngestionError(409, "Source identity collision.")
                if (
                    expected_generations is not None
                    and source_id in expected_generations
                    and source["generation"] != expected_generations[source_id]
                ):
                    raise UrlIngestionError(
                        409,
                        "URL generation changed; reload before requesting a mutation.",
                    )
                if scheduled and (
                    source["status"] not in {"ready", "failed"}
                    or source.get("pending_operation_id") is not None
                    or not source.get("refresh_cadence")
                    or source.get("next_refresh_at") is None
                    or source["next_refresh_at"].replace(tzinfo=timezone.utc)
                    > datetime.now(timezone.utc)
                ):
                    raise UrlIngestionError(
                        409, "Scheduled refresh skipped: source is busy or not due."
                    )
                if source.get("pending_operation_id"):
                    active = one(
                        connection,
                        generations,
                        generations.c.url_ingestion_operation_id
                        == source["pending_operation_id"],
                    )
                    if (
                        active["purpose"] == "delete"
                        or active["cleanup_operations"]
                        or active["stage"] not in {"prepared", "dispatched"}
                    ):
                        raise UrlIngestionError(
                            409, "Another URL operation must finish or reconcile first."
                        )
                owners = [
                    dict(row)
                    for row in connection.execute(
                        sa.select(generations)
                        .where(
                            generations.c.source_id == source_id,
                            generations.c.purpose == "start",
                            generations.c.retired.is_(False),
                        )
                        .order_by(generations.c.generation)
                        .with_for_update()
                    ).mappings()
                ]
                operation = build(source, values)
                operation["cleanup_operations"] = [
                    row["url_ingestion_operation_id"] for row in owners
                ]
                for owner in owners:
                    state = copy.deepcopy(owner["writer_state"])
                    state["managed_writer_gate"] = "closed"
                    connection.execute(
                        generations.update()
                        .where(
                            generations.c.url_ingestion_operation_id
                            == owner["url_ingestion_operation_id"]
                        )
                        .values(writer_state=state)
                    )
                connection.execute(generations.insert().values(**operation))
                connection.execute(
                    sources.update()
                    .where(sources.c.source_id == source_id)
                    .values(
                        principal_id=principal.principal_id,
                        refresh_cadence=(
                            None
                            if values["purpose"] == "delete"
                            else values["body"].get("refresh_cadence")
                        ),
                        next_refresh_at=next_refresh(
                            None
                            if values["purpose"] == "delete"
                            else values["body"].get("refresh_cadence"),
                            datetime.now(timezone.utc),
                        ),
                        generation=operation["generation"],
                        pending_operation_id=operation["url_ingestion_operation_id"],
                        status="deleting"
                        if values["purpose"] == "delete"
                        else "pending",
                        updated_at=sa.func.now(),
                    )
                )
                prepared.append(operation)
            return values, prepared

    @contextmanager
    def execution_owner(self, operation: dict) -> Iterator[None]:
        connection = self.engine.connect()
        acquired = False
        key = lock_key("url-source:" + operation["source_id"])
        try:
            if connection.dialect.name == "postgresql":
                acquired = connection.execute(
                    sa.text("SELECT pg_try_advisory_lock(:key)"), {"key": key}
                ).scalar_one()
                connection.commit()
                if not acquired:
                    raise UrlIngestionError(
                        202, "URL operation is already being reconciled."
                    )
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

    def snapshot(self, operation: dict, *, drained: bool) -> list[dict]:
        with self.engine.begin() as connection:
            source = one(
                connection, sources, sources.c.source_id == operation["source_id"]
            )
            if (
                source["pending_operation_id"]
                != operation["url_ingestion_operation_id"]
                or source["generation"] != operation["generation"]
            ):
                raise UrlIngestionError(409, "URL generation ownership changed.")
            owners = []
            for operation_id in operation["cleanup_operations"]:
                row = one(
                    connection,
                    generations,
                    generations.c.url_ingestion_operation_id == operation_id,
                )
                state = row["writer_state"]
                if state.get("managed_writer_gate") != "closed":
                    raise UrlIngestionError(409, "URL writer fence is not closed.")
                if drained and not writers_released(
                    state | {"workflow_input": row["workflow_input"]}
                ):
                    # An empty ledger is safe only with a reconciled exact run
                    # and verified closure, recorded separately from cancellation.
                    if not (
                        state.get("managed_no_writers_closed") is True
                        and state.get("managed_writers") == {}
                    ):
                        raise UrlIngestionError(
                            202, "Waiting for URL writer drainage or reconciliation."
                        )
                owners.append(row)
            return owners

    def closed_empty(self, operation_id: str, run_id: str) -> None:
        with self.engine.begin() as connection:
            row = one(
                connection,
                generations,
                generations.c.url_ingestion_operation_id == operation_id,
            )
            if row["run_id"] not in {None, run_id}:
                raise UrlIngestionError(409, "URL run identity changed.")
            state = copy.deepcopy(row["writer_state"])
            if (
                state.get("managed_writer_gate") == "closed"
                and state.get("managed_writers") == {}
            ):
                state["managed_no_writers_closed"] = True
                connection.execute(
                    generations.update()
                    .where(generations.c.url_ingestion_operation_id == operation_id)
                    .values(writer_state=state, run_id=run_id)
                )

    def advance(self, operation: dict, stage: str) -> dict:
        self.snapshot(operation, drained=True)
        with self.engine.begin() as connection:
            row = one(
                connection, sources, sources.c.source_id == operation["source_id"]
            )
            if (
                row["pending_operation_id"] != operation["url_ingestion_operation_id"]
                or row["generation"] != operation["generation"]
            ):
                raise UrlIngestionError(409, "URL generation ownership changed.")
            connection.execute(
                generations.update()
                .where(
                    generations.c.url_ingestion_operation_id
                    == operation["url_ingestion_operation_id"]
                )
                .values(stage=stage, updated_at=sa.func.now())
            )
            if stage in {"prepared", "completed"}:
                connection.execute(
                    generations.update()
                    .where(
                        generations.c.url_ingestion_operation_id.in_(
                            operation["cleanup_operations"]
                        )
                    )
                    .values(retired=True)
                )
            if stage == "completed" and operation["purpose"] == "delete":
                connection.execute(
                    sources.update()
                    .where(sources.c.source_id == operation["source_id"])
                    .values(
                        status="deleted",
                        pending_operation_id=None,
                        current_generation=None,
                        updated_at=sa.func.now(),
                    )
                )
        return self.generation(operation["url_ingestion_operation_id"])

    def dispatched(self, operation: dict, receipt: dict) -> None:
        with self.engine.begin() as connection:
            source = one(
                connection, sources, sources.c.source_id == operation["source_id"]
            )
            row = one(
                connection,
                generations,
                generations.c.url_ingestion_operation_id
                == operation["url_ingestion_operation_id"],
            )
            if row["run_id"] not in {None, receipt["run_id"]}:
                raise UrlIngestionError(409, "URL dispatch run differs.")
            control = list(
                connection.execute(
                    sa.select(operations).where(
                        operations.c.workflow_id == row["workflow_id"],
                        operations.c.action == "source",
                    )
                ).mappings()
            )
            if (
                len(control) != 1
                or control[0]["payload"]["workflow_input"] != row["workflow_input"]
            ):
                raise UrlIngestionError(409, "URL dispatch ownership differs.")
            connection.execute(
                generations.update()
                .where(
                    generations.c.url_ingestion_operation_id
                    == row["url_ingestion_operation_id"]
                )
                .values(
                    run_id=receipt["run_id"],
                    workflow_operation_id=control[0]["operation_id"],
                    stage="completed" if row["stage"] == "completed" else "dispatched",
                    writer_state=dict(
                        row["writer_state"],
                        **(
                            {"url_dispatch_protocol": 1}
                            if receipt.get("url_dispatch_protocol") == 1
                            else {}
                        ),
                    ),
                )
            )
            if (
                source["pending_operation_id"] == row["url_ingestion_operation_id"]
                and source["status"] == "pending"
            ):
                connection.execute(
                    sources.update()
                    .where(sources.c.source_id == row["source_id"])
                    .values(status="running")
                )

    def workflow_operation(self, operation: dict) -> dict:
        """Read the existing dispatch identity, including publication-before-receipt."""
        with self.engine.connect() as connection:
            rows = list(
                connection.execute(
                    sa.select(operations).where(
                        operations.c.workflow_id == operation["workflow_id"],
                        operations.c.action == "source",
                    )
                ).mappings()
            )
        if (
            len(rows) != 1
            or rows[0]["payload"]["workflow_input"] != operation["workflow_input"]
        ):
            raise UrlIngestionError(409, "URL dispatch ownership differs.")
        return dict(rows[0])

    def record_workflow_terminal(self, operation: dict, evidence: dict) -> dict:
        """Persist the exact final outcome before allowing Temporal to close."""
        if (
            evidence.get("workflow_id") != operation["workflow_id"]
            or evidence.get("run_id") != operation["run_id"]
            or evidence.get("status") not in {"COMPLETED", "FAILED"}
            or evidence.get("awaiting_ack") is not True
        ):
            raise UrlIngestionError(409, "URL terminal evidence differs.")
        with self.engine.begin() as connection:
            row = one(
                connection,
                generations,
                generations.c.url_ingestion_operation_id
                == operation["url_ingestion_operation_id"],
            )
            if row["run_id"] != evidence["run_id"]:
                raise UrlIngestionError(409, "URL terminal run differs.")
            state = dict(row["writer_state"])
            result = evidence.get("result")
            failed = evidence["status"] == "FAILED" or (
                isinstance(result, dict)
                and result.get("status") in {"failed", "skipped"}
            )
            if (
                not failed
                and not row["retired"]
                and state.get("managed_writer_gate") == "open"
                and row["stage"] != "completed"
            ):
                raise UrlIngestionError(
                    503, "URL publication has not been durably confirmed."
                )
            previous = state.get("url_workflow_terminal")
            if previous is not None and previous != evidence:
                raise UrlIngestionError(409, "URL terminal evidence changed.")
            state["url_workflow_terminal"] = evidence
            connection.execute(
                generations.update()
                .where(
                    generations.c.url_ingestion_operation_id
                    == row["url_ingestion_operation_id"]
                )
                .values(writer_state=state)
            )
        return evidence

    def acknowledge_workflow_terminal(self, operation: dict) -> None:
        with self.engine.begin() as connection:
            row = one(
                connection,
                generations,
                generations.c.url_ingestion_operation_id
                == operation["url_ingestion_operation_id"],
            )
            if row["run_id"] != operation["run_id"]:
                raise UrlIngestionError(409, "URL terminal run differs.")
            state = dict(row["writer_state"], url_workflow_acknowledged=True)
            connection.execute(
                generations.update()
                .where(
                    generations.c.url_ingestion_operation_id
                    == row["url_ingestion_operation_id"]
                )
                .values(writer_state=state)
            )

    def dispatch_owner(self, operation: dict) -> dict:
        """Recheck a selected start while resume holds the source session lock."""
        with self.engine.begin() as connection:
            source = one(
                connection, sources, sources.c.source_id == operation["source_id"]
            )
            row = one(
                connection,
                generations,
                generations.c.url_ingestion_operation_id
                == operation["url_ingestion_operation_id"],
            )
            if (
                source["pending_operation_id"] != row["url_ingestion_operation_id"]
                or source["generation"] != row["generation"]
                or source["status"] not in {"pending", "running"}
                or row["purpose"] != "start"
                or row["stage"] not in {"prepared", "dispatched"}
                or row["retired"]
                or row["writer_state"].get("managed_writer_gate") != "open"
            ):
                raise UrlIngestionError(409, "URL dispatch ownership changed.")
            return row

    def record_external_job(self, operation_id: str, evidence: dict) -> dict:
        with self.engine.begin() as connection:
            row = one(
                connection,
                generations,
                generations.c.url_ingestion_operation_id == operation_id,
            )
            if row is None or evidence.get("job_id") != row["external_job"]["job_id"]:
                raise UrlIngestionError(409, "External job ownership differs.")
            state = dict(row["external_job"])
            # A verified drain is monotone. Observations only update their own
            # generation; they never publish source/current-generation state.
            if state.get("terminal_evidence"):
                from hawki_rag_contracts.pipeline.crawler import CrawlerReceipt

                try:
                    previous = CrawlerReceipt.model_validate(state["terminal_evidence"])
                    previous.verify_owner(row["workflow_input"])
                    if previous.drained:
                        return state
                except ValueError:
                    pass
            state.update(evidence)
            state["observed_at"] = datetime.now(timezone.utc).isoformat()
            connection.execute(
                generations.update()
                .where(generations.c.url_ingestion_operation_id == operation_id)
                .values(external_job=state, updated_at=sa.func.now())
            )
            return state

    def owner(self, connection: Connection, workflow_id: str) -> dict:
        candidates = list(
            connection.execute(
                sa.select(generations).where(generations.c.workflow_id == workflow_id)
            ).mappings()
        )
        controls = list(
            connection.execute(
                sa.select(operations).where(
                    operations.c.workflow_id == workflow_id,
                    operations.c.action == "source",
                )
            ).mappings()
        )
        if len(candidates) != 1 or len(controls) != 1:
            raise UrlIngestionError(
                409, "URL workflow ownership is unknown or ambiguous."
            )
        row = dict(candidates[0])
        wire = controls[0]["payload"]["workflow_input"]
        if (
            wire != row["workflow_input"]
            or wire.get("owner") != "url"
            or any(
                wire.get(key) != row[key]
                for key in (
                    "url_ingestion_operation_id",
                    "source_id",
                    "dataset_id",
                    "task_id",
                    "job_id",
                    "generation",
                )
            )
        ):
            raise UrlIngestionError(
                409, "URL workflow owner differs from its generation."
            )
        return row

    def record_writer(self, event: WriterEvent, payload_hash: str) -> dict:
        with self.engine.begin() as connection:
            lock(connection, "url-event:" + event.event_id)
            row = self.owner(connection, event.workflow_id)
            source = one(connection, sources, sources.c.source_id == row["source_id"])
            row = one(
                connection,
                generations,
                generations.c.url_ingestion_operation_id
                == row["url_ingestion_operation_id"],
            )
            previous = one(connection, events, events.c.event_id == event.event_id)
            if previous and previous["payload_hash"] != payload_hash:
                raise UrlIngestionError(409, "Event ID collision.")
            try:
                state, receipt = update_writer(
                    row
                    | {
                        "lifecycle_status": "started"
                        if row["stage"] in {"prepared", "dispatched"}
                        else row["stage"],
                        "request_metadata": row["writer_state"]
                        | {"workflow_input": row["workflow_input"]},
                    },
                    event,
                    current_owner=(
                        source["pending_operation_id"]
                        == row["url_ingestion_operation_id"]
                        and source["generation"] == row["generation"]
                        and not row["retired"]
                    ),
                )
            except WriterConflict:
                raise UrlIngestionError(
                    409, "URL writer ownership or admission could not be proved."
                ) from None
            state.pop("workflow_input", None)
            connection.execute(
                generations.update()
                .where(
                    generations.c.url_ingestion_operation_id
                    == row["url_ingestion_operation_id"]
                )
                .values(writer_state=state, run_id=row["run_id"] or event.run_id)
            )
            if not previous:
                self._event(
                    connection, event.event_id, payload_hash, row, "writer", receipt
                )
            return receipt

    def complete(self, event: object, payload_hash: str, state: str) -> dict:
        # Attempt failures remain visible in the event ledger, but cannot
        # release the generation while Temporal may still retry the activity.
        if state != "ready":
            state = "running"
        with self.engine.begin() as connection:
            lock(connection, "url-event:" + event.event_id)
            previous = one(connection, events, events.c.event_id == event.event_id)
            if previous:
                if previous["payload_hash"] != payload_hash:
                    raise UrlIngestionError(409, "Event ID collision.")
                return previous["receipt"] | {"duplicate": True}
            row = self.owner(connection, event.workflow_id)
            source = one(connection, sources, sources.c.source_id == row["source_id"])
            row = one(
                connection,
                generations,
                generations.c.url_ingestion_operation_id
                == row["url_ingestion_operation_id"],
            )
            if (
                event.source_id != row["source_id"]
                or event.task_id != row["task_id"]
                or event.job_id != row["job_id"]
                or row["run_id"] not in {None, event.run_id}
            ):
                raise UrlIngestionError(
                    409, "URL callback differs from expected execution."
                )
            if (
                event.stage.value == "scrape"
                and event.metrics.get("crawler_receipt") is not None
            ):
                from hawki_rag_contracts.pipeline.crawler import CrawlerReceipt

                try:
                    crawler = CrawlerReceipt.model_validate(
                        event.metrics["crawler_receipt"]
                    )
                    crawler.verify_owner(row["workflow_input"])
                except ValueError:
                    raise UrlIngestionError(
                        409, "Crawler callback receipt ownership differs."
                    ) from None
                if not crawler.drained:
                    raise UrlIngestionError(
                        409, "Scraper completion requires a sealed crawler receipt."
                    )
                connection.execute(
                    generations.update()
                    .where(
                        generations.c.url_ingestion_operation_id
                        == row["url_ingestion_operation_id"]
                    )
                    .values(
                        external_job=dict(
                            row["external_job"],
                            external_job_id=crawler.external_job_id,
                            lifecycle=crawler.status,
                            sealed=crawler.sealed,
                            sealed_at=crawler.sealed_at.isoformat(),
                            quiescent=True,
                            writer_admission_closed=True,
                            terminal_evidence=event.metrics["crawler_receipt"],
                        )
                    )
                )
            ignored = (
                source["pending_operation_id"] != row["url_ingestion_operation_id"]
                or source["generation"] != row["generation"]
                or row["retired"]
                or row["writer_state"].get("managed_writer_gate") != "open"
            )
            receipt = dict(
                event_id=event.event_id,
                accepted=True,
                duplicate=False,
                ignored=ignored,
                state=state,
                event_type=event.event_type,
                phase=event.phase,
                stage=event.stage.value,
                timestamp=event.occurred_at.isoformat(),
                status=event.status.value,
                counts=event.counts.model_dump(),
                errors=[error.model_dump() for error in event.errors],
                job_id=event.job_id,
                workflow_id=event.workflow_id,
                attempt=event.attempt,
            )
            if not ignored:
                if state == "ready" and not writers_released(
                    row["writer_state"] | {"workflow_input": row["workflow_input"]}
                ):
                    raise UrlIngestionError(
                        409, "Publication requires a released URL writer manifest."
                    )
                if state == "ready":
                    content_identity = verify_manifest(row, event)
                    connection.execute(
                        generations.update()
                        .where(
                            generations.c.url_ingestion_operation_id
                            == row["url_ingestion_operation_id"]
                        )
                        .values(
                            stage="completed",
                            completion=event.model_dump(mode="json"),
                            content_identity=content_identity,
                            run_id=event.run_id,
                        )
                    )
                    fields = dict(
                        status=state,
                        pending_operation_id=None,
                        updated_at=sa.func.now(),
                    )
                    if state == "ready":
                        fields["current_generation"] = row["generation"]
                    connection.execute(
                        sources.update()
                        .where(sources.c.source_id == row["source_id"])
                        .values(**fields)
                    )
            self._event(
                connection, event.event_id, payload_hash, row, "completion", receipt
            )
            return receipt

    def fail_workflow(self, operation: dict, evidence: dict) -> bool:
        """Finalize only the exact terminal Temporal run, under the source lock.

        A completed workflow can return the existing failure envelope instead
        of raising. Neither a running run nor an unknown result proves failure.
        Active/uncertain writers remain in the ledger for retirement to drain.
        """
        status = evidence.get("status")
        result = evidence.get("result")
        failed = status in {"FAILED", "TIMED_OUT", "CANCELED", "TERMINATED"} or (
            status == "COMPLETED"
            and isinstance(result, dict)
            and result.get("status") in {"failed", "skipped"}
        )
        if (
            not operation.get("run_id")
            or evidence.get("workflow_id") != operation["workflow_id"]
            or evidence.get("run_id") != operation["run_id"]
        ):
            raise UrlIngestionError(409, "URL terminal run ownership differs.")
        if not failed:
            return False
        with self.engine.begin() as connection:
            source = one(
                connection, sources, sources.c.source_id == operation["source_id"]
            )
            row = self.owner(connection, operation["workflow_id"])
            row = one(
                connection,
                generations,
                generations.c.url_ingestion_operation_id
                == row["url_ingestion_operation_id"],
            )
            if (
                row["run_id"] != operation["run_id"]
                or row["stage"] not in {"prepared", "dispatched"}
                or row["retired"]
                or source["pending_operation_id"] != row["url_ingestion_operation_id"]
                or source["generation"] != row["generation"]
                or row["writer_state"].get("managed_writer_gate") != "open"
            ):
                return False
            writer_state = copy.deepcopy(row["writer_state"])
            writer_state["managed_writer_gate"] = "closed"
            connection.execute(
                generations.update()
                .where(
                    generations.c.url_ingestion_operation_id
                    == row["url_ingestion_operation_id"]
                )
                .values(
                    stage="completed",
                    writer_state=writer_state,
                    completion={
                        "status": "failed",
                        "occurred_at": datetime.now(timezone.utc).isoformat(),
                        "workflow_id": row["workflow_id"],
                        "run_id": row["run_id"],
                        "workflow_status": status,
                    },
                    updated_at=sa.func.now(),
                )
            )
            connection.execute(
                sources.update()
                .where(sources.c.source_id == row["source_id"])
                .values(
                    status="failed", pending_operation_id=None, updated_at=sa.func.now()
                )
            )
            return True

    @staticmethod
    def _event(
        connection: Connection,
        event_id: str,
        payload_hash: str,
        row: dict,
        kind: str,
        receipt: dict,
    ) -> None:
        connection.execute(
            events.insert().values(
                event_id=event_id,
                payload_hash=payload_hash,
                source_id=row["source_id"],
                url_ingestion_operation_id=row["url_ingestion_operation_id"],
                kind=kind,
                receipt=receipt,
            )
        )
