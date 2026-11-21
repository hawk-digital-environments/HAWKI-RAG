"""Exercise the patched crawler's real API against RAWKI's unchanged consumer."""

import asyncio

import httpx
import pytest

from hawki_crawler.app import job_lifecycle
from hawki_crawler.app.main import app
from hawki_crawler.runtime.network import approve_address, UnsafeDestination
from hawki_rag_contracts.pipeline.crawler import CrawlerReceipt, require_crawler_api


def payload():
    return {
        "job_id": "ingest_" + "a" * 24,
        "dataset_id": "contract-smoke",
        "task_id": "contract-smoke-task",
        "source_id": "source_" + "b" * 32,
        "source_url": "https://example.org/",
        "url": "https://example.org/",
        "generation": 1,
        "url_ingestion_operation_id": "c" * 64,
        "output_dir": "sources/contract-smoke/raw",
        "max_pages": 1,
    }


def test_actual_openapi_satisfies_unchanged_consumer():
    require_crawler_api(app.openapi())


@pytest.mark.asyncio
@pytest.mark.parametrize("field", [name for name in payload() if name != "max_pages"])
async def test_incomplete_permanent_identity_is_rejected_before_admission(field):
    body = payload()
    del body[field]
    async with httpx.AsyncClient(
        transport=httpx.ASGITransport(app=app), base_url="http://crawler"
    ) as client:
        response = await client.post("/crawl", json=body)
    assert response.status_code == 422
    assert job_lifecycle.runtime_store().records() == []


@pytest.mark.asyncio
async def test_owner_is_durable_and_replay_never_starts_a_second_execution(monkeypatch):
    executions = []

    async def execute(job_id, config):
        executions.append(job_id)
        job_lifecycle.jobs[job_id].status = "completed"

    monkeypatch.setattr(job_lifecycle, "run_crawl_job", execute)
    body = payload()
    async with httpx.AsyncClient(
        transport=httpx.ASGITransport(app=app), base_url="http://crawler"
    ) as client:
        response = await client.post("/crawl", json=body)
        assert response.json()["event"] == "job_submitted"
        task = job_lifecycle.tasks.get(body["job_id"])
        if task:
            await task
        response = await client.get("/status/" + body["job_id"])
        receipt = CrawlerReceipt.model_validate(response.json())
        assert receipt.drained
        for field in (
            "dataset_id",
            "task_id",
            "source_id",
            "source_url",
            "generation",
            "url_ingestion_operation_id",
        ):
            assert receipt.metadata[field] == body[field]
        duplicate = await client.post("/crawl", json=body)
        assert duplicate.json()["event"] == "job_exists"
        assert duplicate.json()["data"]["sealed"] is True
        conflict = await client.post("/crawl", json={**body, "task_id": "foreign"})
        assert conflict.status_code == 409
        await asyncio.sleep(0)
        assert executions == [body["job_id"]]


def test_azure_platform_address_is_rejected_at_the_connection_boundary():
    with pytest.raises(UnsafeDestination):
        approve_address("168.63.129.16")
