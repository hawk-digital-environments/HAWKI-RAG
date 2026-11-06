import asyncio
from datetime import datetime, timedelta, timezone


from hawki_backend.url_ingestion.schema import sources
from hawki_backend.url_ingestion.scheduler import tick

from .conftest import create
from .test_lifecycle import generation, ready


def make_due(env, row):
    with env.engine.begin() as connection:
        connection.execute(
            sources.update()
            .where(sources.c.source_id == row["source_id"])
            .values(next_refresh_at=datetime.now(timezone.utc) - timedelta(seconds=1))
        )


def test_refresh_claims_successor_and_busy_tick_skips(env):
    old = generation(env, create(env, refresh_cadence="daily"))
    ready(env, old)
    make_due(env, old)
    assert asyncio.run(tick(env.service))[0]["result"] == "claimed"
    assert env.repository.source("default", old["source_id"])["generation"] == 2
    assert asyncio.run(tick(env.service)) == []
    assert env.port.start_ingest_workflow.await_count == 2
    env.port.delete_schedule.assert_not_awaited()


def test_running_generation_is_never_scheduled(env):
    old = generation(env, create(env, refresh_cadence="daily"))
    make_due(env, old)
    assert asyncio.run(tick(env.service)) == []
    assert env.repository.source("default", old["source_id"])["generation"] == 1


def test_cadence_changes_delete_and_recreate(env):
    old = generation(env, create(env, refresh_cadence="daily"))
    ready(env, old)
    _, weekly = asyncio.run(
        env.service.recrawl(
            env.principal, old["source_id"], {"refresh_cadence": "weekly"}, "weekly"
        )
    )
    assert (
        env.repository.source("default", old["source_id"])["refresh_cadence"]
        == "weekly"
    )
    current = env.repository.source("default", old["source_id"])
    next_time = current["next_refresh_at"].replace(tzinfo=timezone.utc)
    assert next_time > datetime.now(timezone.utc)
    assert next_time.weekday() == 6 and next_time.hour == 2
    ready(env, generation(env, weekly))
    _, none = asyncio.run(
        env.service.recrawl(
            env.principal, old["source_id"], {"refresh_cadence": None}, "none"
        )
    )
    ready(env, generation(env, none))
    assert env.repository.source("default", old["source_id"])["next_refresh_at"] is None
    asyncio.run(env.service.delete(env.principal, old["source_id"], None))
    assert env.repository.source("default", old["source_id"])["refresh_cadence"] is None
    assert asyncio.run(tick(env.service)) == []
    new = generation(env, create(env, "recreate"))
    assert new["generation"] == 5
    assert env.repository.source("default", old["source_id"])["next_refresh_at"] is None


def test_revoked_schedule_principal_cannot_claim(env):
    from hawki_backend.persistence.schema import dataset_grants

    old = generation(env, create(env, refresh_cadence="weekly"))
    ready(env, old)
    make_due(env, old)
    with env.engine.begin() as connection:
        connection.execute(dataset_grants.delete())
    assert asyncio.run(tick(env.service))[0]["result"] == "skipped"
    assert env.repository.source("default", old["source_id"])["generation"] == 1


def test_pending_scheduled_retirement_resumes_same_generation(env):
    from hawki_backend.url_ingestion.errors import UrlIngestionError

    old = generation(env, create(env, refresh_cadence="daily"))
    ready(env, old)
    make_due(env, old)
    env.service.quiescence.ensure.side_effect = UrlIngestionError(202, "remote running")
    assert asyncio.run(tick(env.service))[0]["result"] == "pending"
    assert env.repository.source("default", old["source_id"])["generation"] == 2
    env.service.quiescence.ensure.side_effect = None
    assert asyncio.run(tick(env.service))[0]["result"] == "resumed"
    assert env.repository.source("default", old["source_id"])["generation"] == 2
    assert env.port.start_ingest_workflow.await_count == 2


def test_utc_cadences_follow_daily_sunday_and_calendar_month_boundaries():
    from hawki_backend.url_ingestion.cadence import next_refresh

    now = datetime(2026, 12, 31, 23, 0, tzinfo=timezone.utc)
    assert next_refresh("daily", now) == datetime(2027, 1, 1, 2, tzinfo=timezone.utc)
    assert next_refresh("weekly", now) == datetime(2027, 1, 3, 2, tzinfo=timezone.utc)
    assert next_refresh("monthly", now) == datetime(2027, 1, 1, 2, tzinfo=timezone.utc)
    assert next_refresh(None, now) is None
