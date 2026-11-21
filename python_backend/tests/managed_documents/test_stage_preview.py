from hawki_backend.url_ingestion.reads import logs
from types import SimpleNamespace
from .test_lifecycle import create, revision, ready, released


def test_signed_managed_stage_events_are_readable_without_global_worker_logs(env):
    from hawki_backend.url_ingestion.schema import TABLES
    from hawki_backend.text_ingestion.schema import ingest_text_operations

    for table in (*TABLES, ingest_text_operations):
        table.create(env.engine, checkfirst=True)
    row = revision(env, create(env))
    released(env, row)
    ready(env, row)
    service = SimpleNamespace(
        repository=env.repository, authorize=env.service.authorize
    )
    preview = logs(service, env.principal, row["task_id"], "ingest")["log"]
    assert preview["line_count"] >= 1
    assert "completed" in preview["text"]
    assert "mark_source_ready" in preview["text"]
    assert logs(service, env.principal, row["task_id"], "scraper")["log"]["text"] == ""
