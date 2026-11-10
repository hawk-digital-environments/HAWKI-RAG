import asyncio

import httpx
import pytest

from hawki_backend.url_ingestion.crawler import ExternalCrawlerBoundary
from hawki_backend.url_ingestion.errors import UrlIngestionError

from .crawler_fixtures import api_schema
from .conftest import create
from .test_lifecycle import generation, writer
from .test_quiescence import receipt, boundary


def configured(env, handler):
    env.service.crawler = ExternalCrawlerBoundary(
        "http://crawler.test",
        client_factory=lambda **kw: httpx.Client(
            transport=httpx.MockTransport(handler), **kw
        ),
    )


@pytest.mark.parametrize("mode", ["unavailable", "legacy", "redirect"])
def test_incompatible_crawler_never_claims_or_dispatches(env, mode):
    def handler(request):
        if mode == "unavailable":
            raise httpx.ConnectError("offline")
        return httpx.Response(302 if mode == "redirect" else 200, json={"paths": {}})

    configured(env, handler)
    with pytest.raises(UrlIngestionError) as caught:
        create(env)
    assert caught.value.status == 503
    env.port.start_ingest_workflow.assert_not_called()


def test_compatible_crawler_admits_url_and_safe_sitemap_without_local_fetch(env):
    seen = []

    def handler(request):
        seen.append(str(request.url))
        return httpx.Response(
            200,
            json=api_schema()
            if request.url.path == "/openapi.json"
            else {"status": "healthy"},
        )

    configured(env, handler)
    payload = create(env, sitemap_url="https://example.org/sitemap.xml")
    row = generation(env, payload)
    wire = row["workflow_input"]
    assert (
        wire["metadata"]["request"]["sitemap_url"] == "https://example.org/sitemap.xml"
    )
    assert row["external_job"]["external_job_id"] == row["job_id"]
    assert all(url.startswith("http://crawler.test/") for url in seen)
    env.port.start_ingest_workflow.assert_awaited_once()


@pytest.mark.parametrize(
    "flag", ["sealed", "terminal", "quiescent", "writer_admission_closed"]
)
def test_each_retirement_condition_is_required(env, flag):
    old = generation(env, create(env))
    body = receipt(old)
    body[flag] = False
    env.service.quiescence = boundary(
        env, lambda request: httpx.Response(200, json=body)
    )
    with pytest.raises(UrlIngestionError) as caught:
        asyncio.run(env.service.delete(env.principal, old["source_id"], None))
    assert caught.value.status == 202
    env.service.cleanup.delete.assert_not_called()


def test_sealed_crawler_does_not_release_active_hawki_writer(env):
    old = generation(env, create(env))
    writer(env, old, activity_id="inspect_and_convert_files")
    env.service.quiescence = boundary(
        env, lambda request: httpx.Response(200, json=receipt(old))
    )
    with pytest.raises(UrlIngestionError) as caught:
        asyncio.run(env.service.delete(env.principal, old["source_id"], None))
    assert caught.value.status == 202
    env.service.cleanup.delete.assert_not_called()
    writer(env, old, "release", activity_id="inspect_and_convert_files")
    asyncio.run(env.service.delete(env.principal, old["source_id"], None))
    env.service.cleanup.delete.assert_called_once()


def test_signed_scraper_receipt_persists_execution_evidence_without_publishing_source(
    env,
):
    from .test_lifecycle import event
    from hawki_backend.url_ingestion.identity import digest

    old = generation(env, create(env))
    body = receipt(old)
    callback = event(
        old,
        "scraper-sealed",
        activity_id="scrape_source",
        producer="scraper",
        stage="scrape",
        phase="scrape_source",
        metrics={"crawler_receipt": body},
    )
    env.repository.complete(
        callback, digest(callback.model_dump(mode="json")), "running"
    )
    recorded = env.repository.generation(old["url_ingestion_operation_id"])[
        "external_job"
    ]
    assert recorded["external_job_id"] == old["job_id"]
    assert recorded["sealed"] is True and recorded["terminal_evidence"] == body
    assert env.repository.source("default", old["source_id"])["status"] == "running"
    assert (
        env.repository.source("default", old["source_id"])["current_generation"] is None
    )


def test_receipt_for_another_generation_is_rejected_at_callback(env):
    from .test_lifecycle import event

    old = generation(env, create(env))
    body = receipt(old)
    body["metadata"]["generation"] = 999
    callback = event(
        old,
        "bad-receipt",
        activity_id="scrape_source",
        producer="scraper",
        stage="scrape",
        phase="scrape_source",
        metrics={"crawler_receipt": body},
    )
    with pytest.raises(UrlIngestionError) as caught:
        env.repository.complete(callback, "bad", "running")
    assert caught.value.status == 409
