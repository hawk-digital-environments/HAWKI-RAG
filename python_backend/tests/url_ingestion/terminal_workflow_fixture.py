"""Opt-in probes that replace ingestion, retaining the production terminal gate."""

from temporalio import workflow
from temporalio.exceptions import ApplicationError

from hawki_workflow_worker.workflows.ingest_source import IngestSourceWorkflow


@workflow.defn(name="Epoch11DurableTerminalProbe")
class DurableProbe(IngestSourceWorkflow):
    def __init__(self):
        super().__init__()
        self.finished = False

    @workflow.run
    async def run(self, value: dict) -> dict:
        return await super().run(value)

    async def _ingest(self, value: dict) -> dict:
        await workflow.wait_condition(lambda: self.finished)
        return {"status": "failed", "phase": "scrape_source"}

    @workflow.signal
    def finish(self):
        self.finished = True


@workflow.defn(name="Epoch11FailingDurableTerminalProbe")
class FailingDurableProbe(DurableProbe):
    @workflow.run
    async def run(self, value: dict) -> dict:
        return await super().run(value)

    async def _ingest(self, value: dict) -> dict:
        await super()._ingest(value)
        raise ApplicationError("final probe failure", non_retryable=True)
