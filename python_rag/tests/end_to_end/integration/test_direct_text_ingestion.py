"""Black-box direct-text ingestion test across the running RAWKI stack."""

from __future__ import annotations

from collections.abc import Callable
import hashlib
import json
import os
import time
from typing import Any, NoReturn
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen
from uuid import uuid4

import pytest


pytestmark = pytest.mark.integration


def _required_environment(
    unavailable: Callable[[str], NoReturn],
) -> tuple[str, str, str]:
    token = os.environ.get("RAWKI_INTEGRATION_TEXT_INGEST_TOKEN", "").strip()
    dataset_id = os.environ.get("RAWKI_INTEGRATION_TEXT_INGEST_DATASET_ID", "").strip()
    if not token or not dataset_id:
        unavailable(
            "set RAWKI_INTEGRATION_TEXT_INGEST_TOKEN and "
            "RAWKI_INTEGRATION_TEXT_INGEST_DATASET_ID to a real "
            "rag:text-ingest token with an explicit dataset ingestion grant"
        )

    laravel_url = os.environ.get(
        "RAWKI_INTEGRATION_LARAVEL_URL", "http://127.0.0.1:8080"
    ).rstrip("/")
    return laravel_url, token, dataset_id


def _json_request(
    method: str,
    url: str,
    *,
    payload: dict[str, Any] | None = None,
    headers: dict[str, str] | None = None,
    timeout: float = 10.0,
) -> tuple[int, dict[str, Any]]:
    body = None if payload is None else json.dumps(payload).encode("utf-8")
    request = Request(
        url,
        data=body,
        method=method,
        headers={"Accept": "application/json", **(headers or {})},
    )
    if body is not None:
        request.add_header("Content-Type", "application/json")

    try:
        with urlopen(request, timeout=timeout) as response:  # noqa: S310
            return response.status, json.loads(response.read())
    except HTTPError as exc:
        response_body = exc.read()
        decoded = json.loads(response_body) if response_body else {}
        return exc.code, decoded


def _expected_ids(
    dataset_id: str,
    idempotency_key: str,
    external_document_id: str,
) -> tuple[str, str, str, str]:
    task_hash = hashlib.sha256(f"{dataset_id}|{idempotency_key}".encode()).hexdigest()[
        :32
    ]
    source_hash = hashlib.sha256(
        f"{dataset_id}|{external_document_id}".encode()
    ).hexdigest()[:32]
    task_id = f"task_text_{task_hash}"
    source_id = f"source_{source_hash}"
    job_hash = hashlib.sha256(f"{task_id}|{source_id}".encode()).hexdigest()[:24]
    workflow_hash = hashlib.sha256(task_id.encode()).hexdigest()[:32]
    return task_id, source_id, f"ingest_{job_hash}", f"ingest-text-{workflow_hash}"


def _wait_for_ready_task(laravel_url: str, task_id: str) -> dict[str, Any]:
    deadline = time.monotonic() + float(
        os.environ.get("RAWKI_INTEGRATION_INGEST_TIMEOUT", "180")
    )
    last_status = "not observed"
    while time.monotonic() < deadline:
        status, response = _json_request(
            "GET", f"{laravel_url}/api/pipeline/tasks/{task_id}"
        )
        if status == 200:
            task = response["task"]
            last_status = str(task.get("status"))
            if last_status == "completed":
                return task
            if last_status == "failed":
                pytest.fail(f"Direct ingestion task failed: {task!r}")
        time.sleep(1.0)

    pytest.fail(f"Direct ingestion did not become ready; last status={last_status}")


def _source_points(collection: str, source_id: str) -> list[dict[str, Any]]:
    qdrant_url = os.environ.get(
        "RAWKI_INTEGRATION_QDRANT_URL", "http://127.0.0.1:6333"
    ).rstrip("/")
    headers: dict[str, str] = {}
    qdrant_key = os.environ.get("QDRANT_API_KEY", "").strip()
    if qdrant_key:
        headers["api-key"] = qdrant_key
    status, response = _json_request(
        "POST",
        f"{qdrant_url}/collections/{collection}/points/scroll",
        payload={
            "filter": {"must": [{"key": "source_id", "match": {"value": source_id}}]},
            "limit": 100,
            "with_payload": True,
            "with_vector": False,
        },
        headers=headers,
    )
    assert status == 200, response
    return list(response["result"]["points"])


class TestLiveDirectTextIngestion:
    """Exercise Laravel, the bridge, Temporal, the indexer, and Qdrant."""

    def test_direct_text_reaches_qdrant_and_ready_state(
        self,
        integration_unavailable: Callable[[str], NoReturn],
    ) -> None:
        laravel_url, token, dataset_id = _required_environment(integration_unavailable)
        marker = uuid4().hex
        external_document_id = f"direct-text-it-{marker}"
        idempotency_key = f"direct-text-it-{marker}"
        text = f"# Direct text integration {marker}\n\nExact content: {marker}\n"
        expected = _expected_ids(dataset_id, idempotency_key, external_document_id)

        try:
            dataset_status, dataset_response = _json_request(
                "GET", f"{laravel_url}/api/datasets/{dataset_id}"
            )
            status, response = _json_request(
                "POST",
                f"{laravel_url}/api/integrations/text-ingestions",
                payload={
                    "external_document_id": external_document_id,
                    "dataset_id": dataset_id,
                    "text": text,
                    "content_format": "markdown",
                    "display_name": "Direct text live integration test",
                    "metadata": {"integration_marker": marker},
                },
                headers={
                    "Authorization": f"Bearer {token}",
                    "Idempotency-Key": idempotency_key,
                },
            )
        except (URLError, TimeoutError) as exc:
            integration_unavailable(f"the Laravel API was not reachable ({exc!r})")

        assert dataset_status == 200, dataset_response
        assert status == 202, response
        assert (
            response["task_id"],
            response["source_id"],
            response["job_id"],
            response["workflow_id"],
        ) == expected

        task = _wait_for_ready_task(laravel_url, response["task_id"])
        assert task["dataset_id"] == dataset_id
        assert task["metadata"]["request"]["graph"] is False
        job = next(job for job in task["jobs"] if job["job_id"] == response["job_id"])
        assert job["source_id"] == response["source_id"]
        assert job["temporal_workflow_id"] == response["workflow_id"]
        assert task["status"] == "completed"

        job = task["jobs"][0]
        assert job["status"] == "completed"
        assert job["index_status"] == "ready"
        assert job["error_message"] is None

        source = task["sources"][0]
        assert source["index_status"] == "ready"
        assert source["ready_at"] is not None
        assert job["metadata"]["graph"] is False

        collection = dataset_response["dataset"]["qdrant_collection"]
        try:
            points = _source_points(collection, response["source_id"])
        except (URLError, TimeoutError) as exc:
            integration_unavailable(f"Qdrant was not reachable ({exc!r})")

        assert len(points) == 1
        expected_chunk_content = text.rstrip()
        assert points[0]["payload"]["content"] == expected_chunk_content
        assert points[0]["payload"]["source_id"] == response["source_id"]
        assert points[0]["payload"]["ingestion_mode"] == "direct_text"
