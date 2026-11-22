"""Opt-in service-JWT text ingestion, idempotency, Qdrant and cleanup acceptance."""

from __future__ import annotations

from collections.abc import Callable
import json
import os
import time
from typing import Any, NoReturn
from urllib.error import HTTPError, URLError
from urllib.request import HTTPRedirectHandler, ProxyHandler, Request, build_opener
from uuid import uuid4

import pytest

pytestmark = pytest.mark.integration


class _NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def _json_request(method, url, *, payload=None, headers=None, timeout=10):
    request = Request(
        url,
        data=None if payload is None else json.dumps(payload).encode(),
        method=method,
        headers={"Accept": "application/json", **(headers or {})},
    )
    if payload is not None:
        request.add_header("Content-Type", "application/json")
    opener = build_opener(ProxyHandler({}), _NoRedirect())
    try:
        with opener.open(request, timeout=timeout) as response:
            return response.status, json.load(response)
    except HTTPError as error:
        return error.code, json.load(error)


def _source_points(collection: str, source_id: str) -> list[dict[str, Any]]:
    root = os.environ.get(
        "RAWKI_INTEGRATION_QDRANT_URL", "http://127.0.0.1:6333"
    ).rstrip("/")
    key = os.environ.get("QDRANT_API_KEY", "").strip()
    status, value = _json_request(
        "POST",
        f"{root}/collections/{collection}/points/scroll",
        payload={
            "filter": {"must": [{"key": "source_id", "match": {"value": source_id}}]},
            "limit": 100,
            "with_payload": True,
            "with_vector": False,
        },
        headers={"api-key": key} if key else {},
    )
    if status == 404:
        return []
    assert status == 200, "Qdrant source inventory unavailable"
    return value["result"]["points"]


def test_direct_text_reaches_qdrant_and_ready_state(
    integration_unavailable: Callable[[str], NoReturn],
) -> None:
    token = os.environ.get("RAWKI_INTEGRATION_SERVICE_TOKEN", "").strip()
    dataset = os.environ.get("RAWKI_INTEGRATION_TEXT_INGEST_DATASET_ID", "").strip()
    collection = os.environ.get("RAWKI_INTEGRATION_QDRANT_COLLECTION", "").strip()
    if not all((token, dataset, collection)):
        integration_unavailable(
            "configure a service JWT, disposable ingest-granted dataset and its exact Qdrant collection"
        )
    root = os.environ.get(
        "RAWKI_INTEGRATION_BACKEND_URL", "http://127.0.0.1:8080"
    ).rstrip("/")
    headers = {"Authorization": f"Bearer {token}"}
    try:
        status, _ = _json_request(
            "GET", root + "/v1/pipeline/tasks?limit=1", headers=headers
        )
    except (URLError, TimeoutError):
        integration_unavailable("Python service API is unavailable")
    assert status == 200, "Service JWT must authorize pipeline reads"

    marker = uuid4().hex
    text = f"# Direct text integration {marker}\n\nExact content: {marker}\n"
    payload = {
        "external_document_id": "direct-text-it-" + marker,
        "dataset_id": dataset,
        "text": text,
        "content_format": "markdown",
        "metadata": {"integration_marker": marker},
    }
    admitted = None
    try:
        admission_headers = headers | {"Idempotency-Key": "text-it-" + marker}
        status, admitted = _json_request(
            "POST",
            root + "/v1/text-ingestions",
            payload=payload,
            headers=admission_headers,
        )
        assert status == 202, "Text admission failed"
        status, replay = _json_request(
            "POST",
            root + "/v1/text-ingestions",
            payload=payload,
            headers=admission_headers,
        )
        assert status in {200, 202}
        for field in ("task_id", "source_id", "job_id", "workflow_id"):
            assert replay[field] == admitted[field]
        deadline = time.monotonic() + float(
            os.environ.get("RAWKI_INTEGRATION_INGEST_TIMEOUT", "300")
        )
        while time.monotonic() < deadline:
            status, value = _json_request(
                "GET",
                root + "/v1/pipeline/tasks/" + admitted["task_id"],
                headers=headers,
            )
            assert status == 200
            task = value["task"]
            assert task["status"] not in {"failed", "cancelled"}
            if task["status"] == "completed":
                break
            time.sleep(1)
        else:
            pytest.fail("Text workflow did not complete before deadline")
        assert task["dataset_id"] == dataset
        job = next(job for job in task["jobs"] if job["job_id"] == admitted["job_id"])
        assert job["index_status"] == "ready"
        assert job["source_id"] == admitted["source_id"]
        assert job["temporal_workflow_id"] == admitted["workflow_id"]
        points = _source_points(collection, admitted["source_id"])
        assert len(points) == 1
        assert points[0]["payload"]["content"] == text.rstrip()
        assert points[0]["payload"]["ingestion_mode"] == "direct_text"
    finally:
        if admitted and admitted.get("source_id"):
            deadline = time.monotonic() + 180
            while True:
                status, _ = _json_request(
                    "DELETE",
                    root + "/v1/text-ingestions/" + admitted["source_id"],
                    headers=headers | {"Idempotency-Key": "text-delete-" + marker},
                )
                if status == 200:
                    break
                assert status == 202, "Disposable source cleanup failed"
                if time.monotonic() >= deadline:
                    pytest.fail("Disposable source cleanup remains pending")
                time.sleep(1)
            assert _source_points(collection, admitted["source_id"]) == []
