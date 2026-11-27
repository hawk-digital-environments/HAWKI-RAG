"""Short-lived workflow for requests routed to the scraper queue."""

from datetime import timedelta

from temporalio import workflow
from temporalio.common import RetryPolicy

from hawki_rag_contracts.pipeline.temporal import (
    CRAWLER_CONTROL_ACTIVITY,
    CRAWLER_CONTROL_WORKFLOW,
)


@workflow.defn(name=CRAWLER_CONTROL_WORKFLOW)
class CrawlerControlWorkflow:
    @workflow.run
    async def run(self, action: str, scope: dict | None) -> dict:
        # Keep short control calls off the activity slots used by long crawls.
        return await workflow.execute_local_activity(
            CRAWLER_CONTROL_ACTIVITY,
            args=[action, scope],
            result_type=dict,
            schedule_to_close_timeout=timedelta(seconds=10),
            start_to_close_timeout=timedelta(seconds=9),
            retry_policy=RetryPolicy(maximum_attempts=1),
        )
