"""Reconcile URL workflow outcomes and claim database-backed refreshes.

Run periodically with `python -m hawki_backend.url_ingestion.scheduler`.
Dispatch and terminal reconciliation also cover manual sources with no cadence.
Every tick rechecks the original principal's current exact INGEST grant and
claims through the manual recrawl state machine with a generation compare/swap.
Busy, deleted, changed, overlapping, or no-longer-due sources are skipped.
"""

import asyncio
from datetime import datetime, timedelta, timezone

import sqlalchemy as sa

from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind

from .errors import UrlIngestionError
from .identity import digest
from .schema import sources, requests, generations


async def tick(service, *, limit: int = 100) -> list[dict]:
    # Recover prepared starts regardless of cadence. Keep automatic retirement
    # recovery restricted to already claimed refreshes, as before.
    with service.repository.engine.connect() as connection:
        candidates = (
            sa.select(generations)
            .join(requests, requests.c.request_id == generations.c.request_id)
            .join(
                sources,
                sources.c.pending_operation_id
                == generations.c.url_ingestion_operation_id,
            )
            .where(
                sources.c.generation == generations.c.generation,
                generations.c.retired.is_(False),
            )
            .order_by(generations.c.created_at)
        )
        # A stuck older retirement must not hide prepared manual starts behind
        # the refresh batch limit. Like terminal reconciliation, inventory all.
        pending = list(
            connection.execute(
                candidates.where(
                    generations.c.stage == "prepared",
                    generations.c.purpose == "start",
                    sources.c.status.in_(["pending", "running"]),
                    generations.c.writer_state["managed_writer_gate"].as_string()
                    == "open",
                )
            ).mappings()
        )
        pending.extend(
            connection.execute(
                candidates.where(
                    requests.c.body["_scheduled"].as_boolean().is_(True),
                    generations.c.stage.in_(["fenced", "drained", "cleaned"]),
                ).limit(limit)
            ).mappings()
        )
    results = []
    for operation in pending:
        try:
            await service.resume(dict(operation))
            result = "resumed"
        except UrlIngestionError:
            result = "pending"
        results.append({"source_id": operation["source_id"], "result": result})
    # Every dispatched URL, including manual starts without a cadence, needs
    # terminal reconciliation: an exhausted activity emits no final callback.
    # Do not truncate this inventory behind long-running older executions.
    with service.repository.engine.connect() as connection:
        active = list(
            connection.execute(
                sa.select(generations)
                .join(
                    sources,
                    sources.c.source_id == generations.c.source_id,
                )
                .where(
                    generations.c.run_id.is_not(None),
                    generations.c.purpose == "start",
                    sa.or_(
                        sa.and_(
                            generations.c.stage == "dispatched",
                            sources.c.pending_operation_id
                            == generations.c.url_ingestion_operation_id,
                        ),
                        sa.and_(
                            generations.c.writer_state[
                                "url_dispatch_protocol"
                            ].as_integer()
                            == 1,
                            sa.func.coalesce(
                                generations.c.writer_state[
                                    "url_workflow_acknowledged"
                                ].as_boolean(),
                                False,
                            ).is_(False),
                        ),
                        sa.and_(
                            generations.c.stage == "completed",
                            generations.c.workflow_operation_id.is_(None),
                        ),
                    ),
                )
            ).mappings()
        )
    for operation in active:
        try:
            if not await service.reconcile_workflow(dict(operation)):
                continue
            result = "failed"
        except UrlIngestionError:
            result = "pending"
        results.append({"source_id": operation["source_id"], "result": result})
    with service.repository.engine.connect() as connection:
        due = list(
            connection.execute(
                sa.select(sources)
                .where(
                    sources.c.refresh_cadence.is_not(None),
                    sources.c.next_refresh_at <= sa.func.now(),
                    sources.c.status.in_(["ready", "failed"]),
                    sources.c.pending_operation_id.is_(None),
                )
                .order_by(sources.c.next_refresh_at, sources.c.source_id)
                .limit(limit)
            ).mappings()
        )
    for source in due:
        principal = AuthenticatedPrincipal(
            kind=PrincipalKind.USER,
            subject=source["principal_id"],
            tenant_id=source["tenant_id"],
            client_id="url-refresh-scheduler",
            scopes=frozenset({"rag:ingest"}),
            issuer="internal:url-refresh",
            expires_at=datetime.now(timezone.utc) + timedelta(minutes=1),
        )
        try:
            await service.recrawl(
                principal,
                source["source_id"],
                {},
                digest(
                    [
                        "refresh",
                        source["source_id"],
                        source["generation"],
                        str(source["next_refresh_at"]),
                    ]
                ),
                expected_generation=source["generation"],
                scheduled=True,
            )
            result = "claimed"
        except UrlIngestionError as error:
            result = "skipped" if error.status in {404, 409} else "pending"
        results.append({"source_id": source["source_id"], "result": result})
    return results


async def main() -> None:
    from hawki_backend.main import app

    async with app.router.lifespan_context(app):
        service = app.state.url_ingestion_service
        if service is None:
            raise SystemExit("URL ingestion is not configured")
        for result in await tick(service):
            print(result)


if __name__ == "__main__":
    asyncio.run(main())
