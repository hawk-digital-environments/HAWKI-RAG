"""Database-backed refresh ticks; a schedule contains no ingest workflow input.

Run periodically with `python -m hawki_backend.url_ingestion.scheduler`.
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
    # Reconcile already claimed refreshes before selecting new due work. Their
    # durable generation and idempotency identity survive process/RPC failures.
    with service.repository.engine.connect() as connection:
        pending = list(
            connection.execute(
                sa.select(generations)
                .join(requests, requests.c.request_id == generations.c.request_id)
                .join(
                    sources,
                    sources.c.pending_operation_id
                    == generations.c.url_ingestion_operation_id,
                )
                .where(
                    requests.c.body["_scheduled"].as_boolean().is_(True),
                    generations.c.stage.in_(
                        ["fenced", "drained", "cleaned", "prepared"]
                    ),
                )
                .order_by(generations.c.created_at)
                .limit(limit)
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
