"""Real PostgreSQL/Temporal evidence handoff, using the production URL wrapper."""

import asyncio
import os
import uuid
from concurrent.futures import ThreadPoolExecutor
from dataclasses import replace
from datetime import datetime, timedelta, timezone
from pathlib import Path
from threading import Barrier
from unittest.mock import AsyncMock, patch

import pytest
import sqlalchemy as sa
from temporalio.client import WorkflowExecutionStatus
from temporalio import activity
from temporalio.exceptions import ApplicationError
from temporalio.worker import Worker, UnsandboxedWorkflowRunner

from hawki_backend.url_ingestion.scheduler import tick
from hawki_backend.url_ingestion.schema import generations
from hawki_backend.workflow_control.schema import operations

from .conftest import configure
from .test_dispatch_recovery import ProcessStopped, interrupted_start
from . import test_temporal_dispatch_recovery as dispatch_tests

pg = dispatch_tests.pg
live = dispatch_tests.live


@pytest.fixture
def durable(live, monkeypatch, request):
    # Worker code is imported only for this opt-in integration fixture; it is
    # deliberately not a backend runtime/package dependency.
    root = Path(__file__).resolve().parents[3]
    monkeypatch.syspath_prepend(
        str(root / "python_rag/services/hawki_workflow_worker/src")
    )
    from .terminal_workflow_fixture import DurableProbe, FailingDurableProbe

    mode = getattr(request, "param", "result")

    env, row, settings = live
    settings = replace(
        settings,
        workflow_type="Epoch11FailingDurableTerminalProbe"
        if mode == "failure"
        else "Epoch11DurableTerminalProbe",
    )
    dispatch_tests.attach(env, settings)
    return (
        env,
        row,
        settings,
        FailingDurableProbe if mode == "failure" else DurableProbe,
    )


async def finish_ingestion(client, row):
    handle = client.get_workflow_handle(row["workflow_id"])
    await handle.signal("finish")
    for _ in range(100):
        terminal = await handle.query(
            "url_terminal_outcome", rpc_timeout=timedelta(seconds=5)
        )
        if terminal is not None:
            return terminal
        await asyncio.sleep(0.05)
    raise AssertionError("Probe did not finish ingestion")


async def await_closure(client, row):
    from temporalio.client import WorkflowFailureError

    try:
        await client.get_workflow_handle(row["workflow_id"]).result(
            rpc_timeout=timedelta(seconds=5)
        )
    except WorkflowFailureError:
        pass


@pytest.mark.parametrize("durable", ["result", "failure"], indirect=True)
@pytest.mark.parametrize("receipt_lost", [False, True])
def test_terminal_outcome_survives_outage_without_a_retention_deadline(
    durable, receipt_lost
):
    env, row, settings, probe = durable

    async def scenario():
        client = await dispatch_tests.connect(settings)
        try:
            if receipt_lost:
                with patch.object(
                    env.control.repository, "finish", side_effect=ProcessStopped
                ):
                    with pytest.raises(ProcessStopped):
                        await env.service.resume(row)
            else:
                await env.service.resume(row)
            async with Worker(
                client,
                task_queue=settings.workflow_task_queue,
                workflows=[probe],
                workflow_runner=UnsandboxedWorkflowRunner(),
            ):
                terminal = await finish_ingestion(client, row)
                description = await client.get_workflow_handle(
                    row["workflow_id"]
                ).describe()
                assert description.status == WorkflowExecutionStatus.RUNNING
                config = description.raw_description.execution_config
                assert config.workflow_execution_timeout.ToTimedelta() == timedelta(0)
                assert config.workflow_run_timeout.ToTimedelta() == timedelta(0)
                # Recovery must not depend on ledger age being less than the
                # namespace's retention. No 90-day wall-clock wait is claimed.
                old = datetime.now(timezone.utc) - timedelta(days=90)
                with env.engine.begin() as connection:
                    connection.execute(
                        operations.update().values(created_at=old, updated_at=old)
                    )
                    connection.execute(generations.update().values(created_at=old))
                assert (
                    await client.get_workflow_handle(row["workflow_id"]).query(
                        "url_terminal_outcome"
                    )
                ) == terminal
                recovered = dispatch_tests.attach(
                    configure(env.engine, env.root), settings
                )
                assert (await tick(recovered.service))[-1]["result"] == "failed"
                await await_closure(client, row)
                stored = dispatch_tests.assert_identity(
                    recovered, row, description.run_id
                )
                assert (
                    stored["writer_state"]["url_workflow_terminal"]["status"]
                    == terminal["status"]
                )
                assert stored["writer_state"]["url_workflow_acknowledged"]
                assert (
                    recovered.repository.source("default", row["source_id"])[
                        "pending_operation_id"
                    ]
                    is None
                )
                recovered.port.workflow_status = AsyncMock(
                    side_effect=AssertionError("History may now expire")
                )
                assert await tick(recovered.service) == []
                receipt = await recovered.control.start(
                    "source", row["workflow_id"], row["workflow_input"]
                )
                assert receipt["run_id"] == description.run_id
                recovered.port.start_ingest_workflow.assert_not_awaited()
        finally:
            await dispatch_tests.terminate(client, row["workflow_id"])

    asyncio.run(scenario())


@pytest.mark.parametrize("retry_succeeds", [False, True])
def test_production_sandbox_retries_and_records_final_outcome_before_closing(
    pg, monkeypatch, retry_succeeds
):
    from hawki_workflow_control.settings import WorkflowControlSettings

    address = os.environ.get("HAWKI_EPOCH11_TEMPORAL_ADDRESS")
    if not address:
        pytest.skip("Set HAWKI_EPOCH11_TEMPORAL_ADDRESS for real Temporal validation")
    root = Path(__file__).resolve().parents[3]
    monkeypatch.syspath_prepend(
        str(root / "python_rag/services/hawki_workflow_worker/src")
    )
    from hawki_workflow_worker.workflows.ingest_source import IngestSourceWorkflow

    queue = "epoch11-sandbox-" + uuid.uuid4().hex
    for role in ("WORKFLOW", "SCRAPER", "CONVERTER", "INGESTION", "INDEXER"):
        monkeypatch.setenv(f"TEMPORAL_RAG_{role}_TASK_QUEUE", queue)
    settings = WorkflowControlSettings(
        temporal_address=address, workflow_task_queue=queue
    )
    row = interrupted_start(
        pg, "before_start", source_url="https://example.org/" + queue
    )
    dispatch_tests.attach(pg, settings)
    attempts = []
    attempted = asyncio.Event()

    @activity.defn(name="scrape_source")
    async def failed_scrape(value: dict) -> dict:
        attempts.append(activity.info().attempt)
        attempted.set()
        if not retry_succeeds or activity.info().attempt == 1:
            raise ApplicationError("probe failure", non_retryable=not retry_succeeds)
        return {"status": "success"}

    @activity.defn(name="inspect_and_convert_files")
    async def converted(value: dict) -> dict:
        return {"status": "success"}

    @activity.defn(name="ingest_markdown_files")
    async def indexed(value: dict) -> dict:
        return {"status": "success"}

    @activity.defn(name="mark_source_ready")
    async def published(value: dict) -> dict:
        from .test_lifecycle import ready

        ready(pg, pg.repository.generation(row["url_ingestion_operation_id"]))
        return {"status": "success"}

    async def scenario():
        client = await dispatch_tests.connect(settings)
        try:
            async with Worker(
                client,
                task_queue=queue,
                workflows=[IngestSourceWorkflow],
                activities=[failed_scrape, converted, indexed, published],
            ):
                await pg.service.resume(row)
                handle = client.get_workflow_handle(row["workflow_id"])
                await attempted.wait()
                if retry_succeeds:
                    assert await handle.query("url_terminal_outcome") is None
                    assert await tick(pg.service) == []
                    assert (
                        pg.repository.source("default", row["source_id"])[
                            "pending_operation_id"
                        ]
                        == row["url_ingestion_operation_id"]
                    )
                for _ in range(400):
                    terminal = await handle.query(
                        "url_terminal_outcome", rpc_timeout=timedelta(seconds=5)
                    )
                    if terminal is not None:
                        break
                    await asyncio.sleep(0.05)
                assert terminal["status"] == (
                    "COMPLETED" if retry_succeeds else "FAILED"
                )
                assert (
                    await handle.describe()
                ).status == WorkflowExecutionStatus.RUNNING
                result = await tick(pg.service)
                assert result == (
                    []
                    if retry_succeeds
                    else [{"source_id": row["source_id"], "result": "failed"}]
                )
                await await_closure(client, row)
                assert (await handle.describe()).status == (
                    WorkflowExecutionStatus.COMPLETED
                    if retry_succeeds
                    else WorkflowExecutionStatus.FAILED
                )
                assert attempts == ([1, 2] if retry_succeeds else [1])
                stored = pg.repository.generation(row["url_ingestion_operation_id"])
                assert stored["writer_state"]["url_workflow_acknowledged"]
                assert pg.repository.source("default", row["source_id"])["status"] == (
                    "ready" if retry_succeeds else "failed"
                )
        finally:
            await dispatch_tests.terminate(client, row["workflow_id"])

    asyncio.run(scenario())


def test_restart_after_signal_before_ack_receipt_uses_postgres_outcome(durable):
    env, row, settings, probe = durable

    async def scenario():
        client = await dispatch_tests.connect(settings)
        try:
            await env.service.resume(row)
            async with Worker(
                client,
                task_queue=settings.workflow_task_queue,
                workflows=[probe],
                workflow_runner=UnsandboxedWorkflowRunner(),
            ):
                await finish_ingestion(client, row)
                with patch.object(
                    env.repository,
                    "acknowledge_workflow_terminal",
                    side_effect=ProcessStopped,
                ):
                    with pytest.raises(ProcessStopped):
                        await tick(env.service)
                await await_closure(client, row)
                recovered = dispatch_tests.attach(
                    configure(env.engine, env.root), settings
                )
                recovered.port.workflow_status = AsyncMock(
                    side_effect=AssertionError("Use PostgreSQL evidence")
                )
                assert await tick(recovered.service) == []
                stored = recovered.repository.generation(
                    row["url_ingestion_operation_id"]
                )
                assert stored["writer_state"]["url_workflow_acknowledged"]
                recovered.port.start_ingest_workflow.assert_not_awaited()
                assert await tick(recovered.service) == []
        finally:
            await dispatch_tests.terminate(client, row["workflow_id"])

    asyncio.run(scenario())


def test_concurrent_terminal_handoffs_finalize_once_and_preserve_ack(durable):
    env, row, settings, probe = durable

    async def scenario():
        client = await dispatch_tests.connect(settings)
        try:
            await env.service.resume(row)
            async with Worker(
                client,
                task_queue=settings.workflow_task_queue,
                workflows=[probe],
                workflow_runner=UnsandboxedWorkflowRunner(),
            ):
                await finish_ingestion(client, row)
                instances = [
                    dispatch_tests.attach(configure(env.engine, env.root), settings)
                    for _ in range(2)
                ]
                selected = Barrier(2)
                for instance in instances:
                    reconcile = instance.service.reconcile_workflow

                    async def synchronized(operation, reconcile=reconcile):
                        selected.wait(timeout=10)
                        return await reconcile(operation)

                    instance.service.reconcile_workflow = synchronized

                def concurrent_ticks():
                    with ThreadPoolExecutor(2) as pool:
                        return list(
                            pool.map(
                                lambda item: asyncio.run(tick(item.service)), instances
                            )
                        )

                results = await asyncio.to_thread(concurrent_ticks)
                assert (
                    sum(
                        item["result"] == "failed"
                        for batch in results
                        for item in batch
                    )
                    == 1
                )
                await await_closure(client, row)
                stored = env.repository.generation(row["url_ingestion_operation_id"])
                assert stored["writer_state"]["url_workflow_acknowledged"]
                assert (
                    env.repository.source("default", row["source_id"])[
                        "pending_operation_id"
                    ]
                    is None
                )
        finally:
            await dispatch_tests.terminate(client, row["workflow_id"])

    asyncio.run(scenario())


@pytest.mark.parametrize("mutation", ["delete", "recrawl"])
def test_retirement_can_cancel_a_workflow_waiting_for_terminal_ack(durable, mutation):
    env, row, settings, probe = durable

    async def scenario():
        client = await dispatch_tests.connect(settings)
        try:
            await env.service.resume(row)
            old = env.repository.generation(row["url_ingestion_operation_id"])
            async with Worker(
                client,
                task_queue=settings.workflow_task_queue,
                workflows=[probe],
                workflow_runner=UnsandboxedWorkflowRunner(),
            ):
                await finish_ingestion(client, row)
                if mutation == "delete":
                    await env.service.delete(env.principal, row["source_id"], "delete")
                else:
                    await env.service.recrawl(
                        env.principal, row["source_id"], {}, "recrawl"
                    )
                source = env.repository.source("default", row["source_id"])
                assert source["generation"] == 2
                assert await env.service.reconcile_workflow(old) is False
                assert env.repository.source("default", row["source_id"]) == source
        finally:
            with env.engine.connect() as connection:
                identities = list(
                    connection.execute(sa.select(generations.c.workflow_id)).scalars()
                )
            for identity in identities:
                if identity:
                    await dispatch_tests.terminate(client, identity)

    asyncio.run(scenario())
