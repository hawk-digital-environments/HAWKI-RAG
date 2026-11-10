"""Opt-in PostgreSQL + Temporal checks of dispatch crash and duplicate windows.

Use the disposable *_test DSN and HAWKI_EPOCH11_TEMPORAL_ADDRESS. An isolated
probe workflow/queue exercises the real SDK/server without crawling or sinks.
"""

import asyncio
import os
import uuid
from concurrent.futures import ThreadPoolExecutor
from threading import Barrier
from unittest.mock import AsyncMock, patch

import pytest
import sqlalchemy as sa
from temporalio import workflow
from temporalio.client import Client
from temporalio.service import RPCError, RPCStatusCode
from temporalio.worker import UnsandboxedWorkflowRunner, Worker

from hawki_backend.url_ingestion.scheduler import tick
from hawki_backend.url_ingestion.schema import generations, requests, sources
from hawki_backend.workflow_control.schema import operations
from hawki_backend.workflow_control.service import DispatchUncertain
from hawki_workflow_control.settings import WorkflowControlSettings
from hawki_workflow_control.temporal_client import TemporalControlClient

from .conftest import configure
from .test_dispatch_recovery import ProcessStopped, interrupted_start
from . import test_postgres

pg = test_postgres.pg  # Share the disposable PostgreSQL fixture.


@workflow.defn(name="Epoch11DispatchRecoveryProbe")
class DispatchProbe:
    def __init__(self):
        self.finished = False

    @workflow.run
    async def run(self, value: dict) -> dict:
        await workflow.wait_condition(lambda: self.finished)
        return {"status": "failed", "phase": "scrape_source"}

    @workflow.signal
    def finish(self):
        self.finished = True

    @workflow.query(name="url_terminal_outcome")
    def url_terminal_outcome(self):
        return None


@pytest.fixture
def live(pg):
    address = os.environ.get("HAWKI_EPOCH11_TEMPORAL_ADDRESS")
    if not address:
        pytest.skip("Set HAWKI_EPOCH11_TEMPORAL_ADDRESS for real Temporal validation")
    settings = WorkflowControlSettings(
        temporal_address=address,
        temporal_namespace=os.environ.get(
            "HAWKI_EPOCH11_TEMPORAL_NAMESPACE", "default"
        ),
        workflow_type="Epoch11DispatchRecoveryProbe",
        workflow_task_queue="epoch11-dispatch-test-" + uuid.uuid4().hex,
    )
    row = interrupted_start(
        pg, "before_start", source_url="https://example.org/" + uuid.uuid4().hex
    )
    attach(pg, settings)
    return pg, row, settings


def attach(env, settings):
    port = TemporalControlClient(settings)
    port.start_ingest_workflow = AsyncMock(wraps=port.start_ingest_workflow)
    port.reconcile_start = AsyncMock(wraps=port.reconcile_start)
    env.port = env.control.port = port
    return env


def assert_identity(env, before, run_id):
    stored = env.repository.generation(before["url_ingestion_operation_id"])
    for key in (
        "source_id",
        "generation",
        "url_ingestion_operation_id",
        "request_id",
        "workflow_id",
        "workflow_input",
        "task_id",
        "job_id",
    ):
        assert stored[key] == before[key]
    assert stored["run_id"] == run_id
    with env.engine.connect() as connection:
        for table in (sources, generations, requests, operations):
            assert connection.scalar(sa.select(sa.func.count()).select_from(table)) == 1
        ledger = connection.execute(sa.select(operations)).mappings().one()
    assert ledger["status"] == "confirmed"
    assert ledger["receipt"]["run_id"] == run_id
    assert stored["workflow_operation_id"] == ledger["operation_id"]
    return stored


async def connect(settings):
    return await Client.connect(
        settings.temporal_address, namespace=settings.temporal_namespace
    )


async def terminate(client, workflow_id):
    try:
        await client.get_workflow_handle(workflow_id).terminate(
            reason="Epoch 11 test cleanup"
        )
    except RPCError as error:
        if error.status not in {
            RPCStatusCode.NOT_FOUND,
            RPCStatusCode.FAILED_PRECONDITION,
        }:
            raise


@pytest.mark.parametrize(
    "boundary",
    [
        "before_claim",
        "after_claim",
        "response_lost",
        "control_receipt",
        "url_receipt",
        "completed",
    ],
)
def test_real_temporal_all_dispatch_crash_windows_converge(live, boundary):
    env, row, settings = live

    async def scenario():
        client = await connect(settings)
        try:
            if boundary == "after_claim":
                claim = env.control.repository.claim

                def crash(operation_id, **kwargs):
                    assert claim(operation_id, **kwargs)
                    raise ProcessStopped()

                with patch.object(env.control.repository, "claim", side_effect=crash):
                    with pytest.raises(ProcessStopped):
                        await env.service.resume(row)
            elif boundary in {
                "response_lost",
                "control_receipt",
                "url_receipt",
                "completed",
            }:
                if boundary == "response_lost":
                    start = env.port.start_ingest_workflow

                    async def accepted_then_crashed(**kwargs):
                        await start(**kwargs)
                        raise ProcessStopped()

                    target, effect = (
                        (env.port, "start_ingest_workflow"),
                        accepted_then_crashed,
                    )
                elif boundary == "url_receipt":
                    target, effect = (env.repository, "dispatched"), ProcessStopped
                else:
                    target, effect = (env.control.repository, "finish"), ProcessStopped
                with patch.object(*target, side_effect=effect):
                    with pytest.raises(ProcessStopped):
                        await env.service.resume(row)
            if boundary in {"before_claim", "after_claim"}:
                with pytest.raises(RPCError) as absent:
                    await client.get_workflow_handle(row["workflow_id"]).describe()
                assert absent.value.status == RPCStatusCode.NOT_FOUND
                if boundary == "after_claim":
                    with env.engine.connect() as connection:
                        ledger = (
                            connection.execute(sa.select(operations)).mappings().one()
                        )
                    assert ledger["status"] == "dispatching"
                    assert ledger["receipt"] is None
            recovered = attach(configure(env.engine, env.root), settings)
            async with Worker(
                client,
                task_queue=settings.workflow_task_queue,
                workflows=[DispatchProbe],
                workflow_runner=UnsandboxedWorkflowRunner(),
            ):
                if boundary == "completed":
                    await client.get_workflow_handle(row["workflow_id"]).signal(
                        "finish"
                    )
                    await client.get_workflow_handle(row["workflow_id"]).result()
                results = await tick(recovered.service)
                assert results[0]["result"] == "resumed"
                description = await client.get_workflow_handle(
                    row["workflow_id"]
                ).describe()
                run_id = description.run_id
                stored = assert_identity(recovered, row, run_id)
                if boundary != "completed":
                    assert stored["stage"] == "dispatched"
                    assert await tick(recovered.service) == []
                    await client.get_workflow_handle(
                        row["workflow_id"], run_id=run_id
                    ).signal("finish")
                    await client.get_workflow_handle(
                        row["workflow_id"], run_id=run_id
                    ).result()
                    assert (await tick(recovered.service))[-1]["result"] == "failed"
                assert await tick(recovered.service) == []
                assert (
                    recovered.repository.source("default", row["source_id"])[
                        "pending_operation_id"
                    ]
                    is None
                )
                assert (
                    await client.get_workflow_handle(row["workflow_id"]).describe()
                ).run_id == run_id
                assert recovered.port.start_ingest_workflow.await_count == (
                    1 if boundary in {"before_claim", "after_claim"} else 0
                )
        finally:
            await terminate(client, row["workflow_id"])

    asyncio.run(scenario())


@pytest.mark.parametrize("closed", [False, True])
def test_real_temporal_rejects_duplicate_when_workflow_appears_after_absent_lookup(
    live, closed
):
    env, row, settings = live
    claim = env.control.repository.claim

    def crash(operation_id, **kwargs):
        assert claim(operation_id, **kwargs)
        raise ProcessStopped()

    with patch.object(env.control.repository, "claim", side_effect=crash):
        with pytest.raises(ProcessStopped):
            asyncio.run(env.service.resume(row))
    with env.engine.connect() as connection:
        operation_id = connection.scalar(sa.select(operations.c.operation_id))

    async def scenario():
        client = await connect(settings)
        recovered = attach(configure(env.engine, env.root), settings)
        reconcile = recovered.port.reconcile_start
        winner = None

        async def lookup_then_competing_start(**kwargs):
            nonlocal winner
            assert await reconcile(**kwargs) is None
            winner = await env.port.start_ingest_workflow(
                workflow_id=row["workflow_id"],
                workflow_input=row["workflow_input"],
                control_operation=operation_id,
            )
            if closed:
                await client.get_workflow_handle(row["workflow_id"]).signal("finish")
                await client.get_workflow_handle(row["workflow_id"]).result()
            return None

        recovered.port.reconcile_start = AsyncMock(
            side_effect=lookup_then_competing_start
        )
        try:
            async with Worker(
                client,
                task_queue=settings.workflow_task_queue,
                workflows=[DispatchProbe],
                workflow_runner=UnsandboxedWorkflowRunner(),
            ):
                assert (await tick(recovered.service))[0]["result"] == "resumed"
                assert_identity(recovered, row, winner.run_id)
                assert (
                    await client.get_workflow_handle(row["workflow_id"]).describe()
                ).run_id == winner.run_id
                recovered.port.start_ingest_workflow.assert_awaited_once()
                assert await tick(recovered.service) == []
        finally:
            await terminate(client, row["workflow_id"])

    asyncio.run(scenario())


def test_real_postgres_two_schedulers_recover_claimed_operation_once(live):
    env, row, settings = live
    claim = env.control.repository.claim

    def crash(operation_id, **kwargs):
        assert claim(operation_id, **kwargs)
        raise ProcessStopped()

    with patch.object(env.control.repository, "claim", side_effect=crash):
        with pytest.raises(ProcessStopped):
            asyncio.run(env.service.resume(row))
    instances = [attach(configure(env.engine, env.root), settings) for _ in range(2)]
    selected = Barrier(2)
    for instance in instances:
        resume = instance.service.resume

        async def synchronized(operation, resume=resume):
            selected.wait(timeout=10)
            await resume(operation)

        instance.service.resume = synchronized
    with ThreadPoolExecutor(2) as pool:
        results = list(
            pool.map(lambda item: asyncio.run(tick(item.service)), instances)
        )
    assert any(result[0]["result"] == "resumed" for result in results)
    assert sum(item.port.start_ingest_workflow.await_count for item in instances) == 1

    async def inspect():
        client = await connect(settings)
        try:
            description = await client.get_workflow_handle(
                row["workflow_id"]
            ).describe()
            assert_identity(env, row, description.run_id)
            async with Worker(
                client,
                task_queue=settings.workflow_task_queue,
                workflows=[DispatchProbe],
                workflow_runner=UnsandboxedWorkflowRunner(),
            ):
                assert await tick(instances[0].service) == []
        finally:
            await terminate(client, row["workflow_id"])

    asyncio.run(inspect())


@pytest.mark.parametrize("race", ["late_rpc", "concurrent_rpc", "late_timeout"])
def test_real_temporal_concurrent_first_and_recovery_rpc_share_one_run(live, race):
    env, row, settings = live
    recovered = attach(configure(env.engine, env.root), settings)

    async def scenario():
        client = await connect(settings)
        submitted = asyncio.Event()
        release = asyncio.Event()
        start = env.port.start_ingest_workflow

        async def delayed_first(**kwargs):
            if race == "late_timeout":
                await start(**kwargs)
            submitted.set()
            await release.wait()
            if race == "late_timeout":
                raise TimeoutError()
            return await start(**kwargs)

        env.port.start_ingest_workflow = AsyncMock(side_effect=delayed_first)
        if race == "concurrent_rpc":
            recovery_start = recovered.port.start_ingest_workflow

            async def simultaneous(**kwargs):
                release.set()
                return await recovery_start(**kwargs)

            recovered.port.start_ingest_workflow = AsyncMock(side_effect=simultaneous)

        async def original():
            return await env.control.start(
                "source", row["workflow_id"], row["workflow_input"]
            )

        first = asyncio.create_task(original())
        try:
            await submitted.wait()
            # A separate process sees the committed claim while the original RPC
            # has not arrived. Let recovery win, then deliver the delayed RPC.
            receipt = await recovered.control.start(
                "source", row["workflow_id"], row["workflow_input"]
            )
            release.set()
            if race == "late_timeout":
                with pytest.raises(DispatchUncertain):
                    await first
            else:
                assert await first == receipt
            env.repository.dispatched(row, receipt)
            assert_identity(env, row, receipt["run_id"])
            async with Worker(
                client,
                task_queue=settings.workflow_task_queue,
                workflows=[DispatchProbe],
                workflow_runner=UnsandboxedWorkflowRunner(),
            ):
                assert await tick(recovered.service) == []
            assert (
                await client.get_workflow_handle(row["workflow_id"]).describe()
            ).run_id == receipt["run_id"]
        finally:
            release.set()
            await asyncio.gather(first, return_exceptions=True)
            await terminate(client, row["workflow_id"])

    asyncio.run(scenario())
