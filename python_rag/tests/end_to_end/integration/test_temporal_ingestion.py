"""Live Temporal orchestration test for the production ingestion workflow.

The production ``IngestSourceWorkflow`` runs on a unique test task queue while
small test activities return deterministic stage results.  Temporal itself is
real: workflow history, task dispatch, serialization, and activity hand-offs
all pass through the configured server.  No crawler, database, or dataset is
changed by this compatibility test.
"""

from __future__ import annotations

import asyncio
from contextlib import suppress
from datetime import timedelta
import importlib
import os
from typing import Any, Callable, NoReturn
from uuid import uuid4

import pytest


pytestmark = pytest.mark.integration


def _probe_timeout() -> float:
    try:
        return max(
            0.5,
            float(os.environ.get("RAWKI_INTEGRATION_PROBE_TIMEOUT", "1.5")),
        )
    except ValueError:
        return 1.5


def _temporal_addresses() -> list[str]:
    values = [
        os.environ.get("RAWKI_INTEGRATION_TEMPORAL_ADDRESS", ""),
        os.environ.get("TEMPORAL_ADDRESS", ""),
        "127.0.0.1:7233",
        "temporal:7233",
    ]
    seen: set[str] = set()
    addresses: list[str] = []
    for value in values:
        address = str(value or "").strip()
        if not address or address in seen:
            continue
        seen.add(address)
        addresses.append(address)
    return addresses


async def _connect_temporal(client_type: Any) -> tuple[Any | None, list[str]]:
    namespace = os.environ.get("TEMPORAL_NAMESPACE", "default").strip() or "default"
    failures: list[str] = []
    for address in _temporal_addresses():
        try:
            client = await asyncio.wait_for(
                client_type.connect(address, namespace=namespace),
                timeout=_probe_timeout(),
            )
            healthy = await asyncio.wait_for(
                client.service_client.check_health(),
                timeout=_probe_timeout(),
            )
            if healthy:
                return client, failures
            failures.append(f"{address} (health check returned false)")
        except Exception as exc:  # Temporal wraps gRPC failures by transport type.
            failures.append(f"{address} ({type(exc).__name__})")
    return None, failures


async def _run_live_ingestion_workflow(
    *,
    unavailable: Callable[[str], NoReturn],
) -> dict[str, Any]:
    try:
        activity = importlib.import_module("temporalio.activity")
        client_module = importlib.import_module("temporalio.client")
        worker_module = importlib.import_module("temporalio.worker")
    except ImportError:
        unavailable("the 'temporalio' package is not installed; run `make python-deps`")

    from hawki_workflow_worker.workflows.ingest_source import IngestSourceWorkflow

    client, failures = await _connect_temporal(client_module.Client)
    if client is None:
        unavailable(
            "Temporal was not reachable; set RAWKI_INTEGRATION_TEMPORAL_ADDRESS/"
            "TEMPORAL_ADDRESS or start the Compose temporal service "
            f"(probes: {', '.join(failures) or 'none'})"
        )

    token = uuid4().hex
    task_queue = f"rawki-it-ingestion-{token}"
    workflow_id = f"rawki-it-ingestion-{token}"
    marker = f"rawki-it-marker-{token}"

    @activity.defn(name="scrape_source")
    async def scrape_source(workflow_input: dict[str, Any]) -> dict[str, Any]:
        assert workflow_input["integration_marker"] == marker
        return {"status": "success", "scrape_marker": marker}

    @activity.defn(name="inspect_and_convert_files")
    async def inspect_and_convert_files(
        activity_input: dict[str, Any],
    ) -> dict[str, Any]:
        assert activity_input["scrape_result"]["scrape_marker"] == marker
        return {"status": "success", "convert_marker": marker}

    @activity.defn(name="ingest_markdown_files")
    async def ingest_markdown_files(activity_input: dict[str, Any]) -> dict[str, Any]:
        assert activity_input["scrape_result"]["scrape_marker"] == marker
        assert activity_input["convert_result"]["convert_marker"] == marker
        return {"status": "success", "ingest_marker": marker}

    @activity.defn(name="mark_source_ready")
    async def mark_source_ready(activity_input: dict[str, Any]) -> dict[str, Any]:
        workflow_input = activity_input["workflow_input"]
        assert activity_input["ingest_result"]["ingest_marker"] == marker
        return {
            "status": "success",
            "source_id": workflow_input["source_id"],
            "dataset_id": workflow_input["dataset_id"],
            "integration_marker": marker,
            "completed_stages": [
                "scrape_source",
                "inspect_and_convert_files",
                "ingest_markdown_files",
                "mark_source_ready",
            ],
        }

    workflow_input = {
        "source_id": f"rawki-it-source-{token}",
        "dataset_id": f"rawki-it-dataset-{token}",
        "source_url": f"integration://rawki/{token}",
        "integration_marker": marker,
        "task_queues": {
            "scraper": task_queue,
            "converter": task_queue,
            "indexer": task_queue,
            "ingestion": task_queue,
        },
    }
    worker = worker_module.Worker(
        client,
        task_queue=task_queue,
        workflows=[IngestSourceWorkflow],
        activities=[
            scrape_source,
            inspect_and_convert_files,
            ingest_markdown_files,
            mark_source_ready,
        ],
    )

    handle = None
    completed = False
    async with worker:
        try:
            handle = await client.start_workflow(
                IngestSourceWorkflow.run,
                workflow_input,
                id=workflow_id,
                task_queue=task_queue,
                execution_timeout=timedelta(seconds=60),
                run_timeout=timedelta(seconds=45),
                task_timeout=timedelta(seconds=10),
            )
            result = await asyncio.wait_for(handle.result(), timeout=30.0)
            completed = True
            return result
        finally:
            if handle is not None and not completed:
                with suppress(Exception):
                    await asyncio.wait_for(handle.cancel(), timeout=5.0)


class TestLiveTemporalIngestion:
    """Verify the production ingestion workflow executes all stages on Temporal."""

    def test_ingestion_workflow_dispatches_each_stage_and_returns_ready_result(
        self,
        integration_unavailable: Callable[[str], NoReturn],
    ) -> None:
        result = asyncio.run(
            _run_live_ingestion_workflow(unavailable=integration_unavailable)
        )

        assert result["status"] == "success"
        assert result["source_id"].startswith("rawki-it-source-")
        assert result["dataset_id"].startswith("rawki-it-dataset-")
        assert result["integration_marker"].startswith("rawki-it-marker-")
        assert result["completed_stages"] == [
            "scrape_source",
            "inspect_and_convert_files",
            "ingest_markdown_files",
            "mark_source_ready",
        ]
