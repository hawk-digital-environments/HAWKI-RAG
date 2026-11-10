"""A URL's final result cannot enter retention before durable acknowledgement."""

import asyncio
from types import SimpleNamespace
from unittest.mock import AsyncMock

import pytest
from temporalio.exceptions import ActivityError, ApplicationError, RetryState

from hawki_workflow_worker.workflows import ingest_source
from hawki_workflow_worker.workflows.ingest_source import IngestSourceWorkflow


@pytest.mark.parametrize("failure", [None, "application", "activity"])
def test_url_remains_open_without_repeating_ingestion_until_exact_ack(
    monkeypatch, failure
):
    async def scenario():
        waiting = asyncio.Event()
        changed = asyncio.Event()

        async def wait_condition(predicate):
            waiting.set()
            while not predicate():
                await changed.wait()
                changed.clear()

        monkeypatch.setattr(
            ingest_source,
            "workflow",
            SimpleNamespace(
                memo=lambda: {
                    "hawki_url_dispatch_protocol": 1,
                    "hawki_control_operation": "operation",
                },
                info=lambda: SimpleNamespace(run_id="exact-run"),
                wait_condition=wait_condition,
            ),
        )
        instance = IngestSourceWorkflow()
        instance._ingest = AsyncMock(return_value={"status": "success"})
        if failure == "application":
            instance._ingest.side_effect = ApplicationError(
                "final failure", non_retryable=True
            )
        elif failure == "activity":
            instance._ingest.side_effect = ActivityError(
                "retries exhausted",
                scheduled_event_id=1,
                started_event_id=2,
                identity="worker",
                activity_type="scrape_source",
                activity_id="scrape",
                retry_state=RetryState.MAXIMUM_ATTEMPTS_REACHED,
            )
        run = asyncio.create_task(instance.run({"owner": "url"}))
        await waiting.wait()
        assert not run.done()
        assert instance.url_terminal_outcome()["status"] == (
            "FAILED" if failure else "COMPLETED"
        )
        for receipt in (
            {"run_id": "foreign", "control_operation": "operation"},
            {"run_id": "exact-run", "control_operation": "foreign"},
        ):
            instance.acknowledge_url_terminal(receipt)
            assert not instance._url_terminal_acknowledged
        instance.acknowledge_url_terminal(
            {"run_id": "exact-run", "control_operation": "operation"}
        )
        changed.set()
        if failure:
            with pytest.raises((ApplicationError, ActivityError)):
                await run
        else:
            assert await run == {"status": "success"}
        instance._ingest.assert_awaited_once()

    asyncio.run(scenario())


@pytest.mark.parametrize("owner,memo", [(None, {}), ("url", {})])
def test_legacy_histories_keep_original_orchestration(monkeypatch, owner, memo):
    monkeypatch.setattr(ingest_source, "workflow", SimpleNamespace(memo=lambda: memo))
    instance = IngestSourceWorkflow()
    instance._ingest = AsyncMock(return_value={"status": "failed"})
    assert asyncio.run(instance.run({"owner": owner})) == {"status": "failed"}
    assert instance.url_terminal_outcome() is None
