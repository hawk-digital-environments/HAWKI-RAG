from pathlib import Path
from unittest.mock import Mock

import pytest
import requests

from hawki_external_jobs.crawler import CustomCrawlerClient


def schema():
    return {
        "paths": {
            "/crawl": {
                "post": {
                    "requestBody": {
                        "content": {
                            "application/json": {
                                "schema": {"$ref": "#/components/schemas/CrawlConfig"}
                            }
                        }
                    }
                }
            },
            "/status/{job_id}": {
                "get": {
                    "parameters": [
                        {"name": "job_id", "schema": {"pattern": "permanent-job"}}
                    ],
                    "responses": {
                        "200": {"content": {"application/json": {"schema": {}}}}
                    },
                }
            },
            "/jobs/{job_id}/cancel": {"post": {}},
        },
        "components": {
            "schemas": {
                "CrawlConfig": {
                    "properties": {
                        key: {"pattern": "permanent-job"}
                        for key in (
                            "job_id",
                            "source_id",
                            "source_url",
                            "generation",
                            "url_ingestion_operation_id",
                            "url",
                            "output_dir",
                            "sitemap",
                            "sitemap_base",
                            "sitemap_only",
                        )
                    }
                }
            }
        },
    }


def wire(tmp_path):
    return dict(
        owner="url",
        job_id="permanent-one",
        source_id="source-one",
        source_url="https://example.org",
        generation=1,
        url_ingestion_operation_id="a" * 64,
        raw_output_path=str(tmp_path / "raw"),
        storage={"shared_root": str(tmp_path)},
    )


def receipt(wire, sealed=True):
    return dict(
        job_id=wire["job_id"],
        external_job_id=wire["job_id"],
        status="completed",
        state="completed",
        terminal=True,
        sealed=sealed,
        sealed_at="2026-11-09T12:00:00Z" if sealed else None,
        quiescent=sealed,
        writer_admission_closed=sealed,
        pages_crawled=1,
        output_directory=str(Path(wire["raw_output_path"]) / wire["job_id"]),
        metadata={
            key: wire[key]
            for key in (
                "source_id",
                "source_url",
                "generation",
                "url_ingestion_operation_id",
            )
        },
    )


def client(tmp_path):
    value = wire(tmp_path)
    return CustomCrawlerClient(
        workflow_input=value,
        base_url="http://crawler.test",
        start_path="/crawl",
        status_path="/status/{job_id}",
        poll_interval_seconds=0.5,
    ), value


def test_terminal_without_seal_keeps_polling(tmp_path, monkeypatch):
    adapter, value = client(tmp_path)
    monkeypatch.setattr("hawki_external_jobs.crawler.time.sleep", lambda _: None)
    adapter._request = Mock(
        side_effect=[
            schema(),
            {"status": "healthy"},
            {
                "job_id": value["job_id"],
                "event": "job_submitted",
                "data": {"sealed": False},
            },
            receipt(value, False),
            receipt(value),
        ]
    )
    progress = []
    assert (
        adapter.start_and_wait(
            {"job_id": value["job_id"]}, progress_callback=progress.append
        )["sealed"]
        is True
    )
    assert len(progress) == 3
    assert adapter._request.call_count == 5


def test_ambiguous_post_delivery_and_activity_replay_execute_one_permanent_job(
    tmp_path, monkeypatch
):
    adapter, value = client(tmp_path)
    monkeypatch.setattr("hawki_external_jobs.client.time.sleep", lambda _: None)
    jobs = {}
    submissions = []
    lost = True

    def request(method, url, **kwargs):
        nonlocal lost
        response = Mock(status_code=200)
        if url.endswith("openapi.json"):
            body = schema()
        elif url.endswith("health"):
            body = {"status": "healthy"}
        elif method == "POST":
            payload = kwargs["json"]
            submissions.append(payload.copy())
            jobs.setdefault(payload["job_id"], 1)
            if lost:
                lost = False
                raise requests.ConnectionError("accepted but response lost")
            body = {
                "job_id": value["job_id"],
                "event": "job_exists",
                "data": {"sealed": True},
            }
        else:
            body = receipt(value)
        response.json.return_value = body
        return response

    adapter.session.request = Mock(side_effect=request)
    for resume in (None, value["job_id"]):
        adapter.start_and_wait({"job_id": value["job_id"]}, resume_job_id=resume)
    assert jobs == {value["job_id"]: 1}
    assert len(submissions) == 3
    assert all(payload == submissions[0] for payload in submissions)
    assert all(
        call.kwargs["allow_redirects"] is False
        for call in adapter.session.request.call_args_list
    )


@pytest.mark.parametrize(
    "mode", ["legacy", "unreachable", "wrong_job", "wrong_generation"]
)
def test_incompatible_crawler_or_receipt_fails_closed(tmp_path, mode):
    adapter, value = client(tmp_path)
    body = receipt(value)
    if mode == "wrong_job":
        body["external_job_id"] = "other"
    if mode == "wrong_generation":
        body["metadata"]["generation"] = 99
    adapter._request = Mock(
        side_effect=[
            {} if mode == "legacy" else schema(),
            {"status": "healthy"},
            {
                "job_id": value["job_id"],
                "event": "job_submitted",
                "data": {"sealed": False},
            },
            body,
        ]
    )
    if mode == "unreachable":
        adapter._request.side_effect = RuntimeError("offline")
    with pytest.raises((ValueError, RuntimeError)):
        adapter.start_and_wait({"job_id": value["job_id"]})
