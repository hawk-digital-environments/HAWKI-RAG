"""Read only explicitly supplied workflow histories inside the backend container.

Input: JSON array of workflow IDs on stdin. Output excludes workflow payloads,
activity inputs, failure messages, and credentials. Does not cancel/start work.
"""

import asyncio
import json
import os
import sys


def activity_metrics(events):
    scheduled = {}
    result = []
    for event in events:
        kind = event["kind"]
        if kind == "scheduled":
            scheduled[event["id"]] = event
        elif kind == "started":
            parent = scheduled[event["scheduled_id"]]
            parent["started"] = event
        elif kind in {"completed", "failed", "timed_out", "cancelled"}:
            parent = scheduled.get(event["scheduled_id"])
            if not parent or "started" not in parent:
                continue
            start = parent["started"]
            result.append(
                {
                    "activity": parent["activity"],
                    "queue": parent["queue"],
                    "scheduled_ns": parent["time_ns"],
                    "started_ns": start["time_ns"],
                    "finished_ns": event["time_ns"],
                    "attempt": start["attempt"],
                    "schedule_to_start_ms": round(
                        (start["time_ns"] - parent["time_ns"]) / 1e6, 3
                    ),
                    "execution_ms": round(
                        (event["time_ns"] - start["time_ns"]) / 1e6, 3
                    ),
                    "status": kind,
                }
            )
    return result


async def collect(ids):
    from temporalio.client import Client
    from temporalio.api.enums.v1 import EventType

    client = await Client.connect(
        os.environ["TEMPORAL_ADDRESS"],
        namespace=os.environ.get("TEMPORAL_NAMESPACE", "default"),
    )

    async def one(workflow_id):
        history = await client.get_workflow_handle(workflow_id).fetch_history()
        rows = []
        for event in history.events:
            row = {"id": event.event_id, "time_ns": event.event_time.ToNanoseconds()}
            if event.event_type == EventType.EVENT_TYPE_ACTIVITY_TASK_SCHEDULED:
                value = event.activity_task_scheduled_event_attributes
                row.update(
                    kind="scheduled",
                    activity=value.activity_type.name,
                    queue=value.task_queue.name,
                )
            elif event.event_type == EventType.EVENT_TYPE_ACTIVITY_TASK_STARTED:
                value = event.activity_task_started_event_attributes
                row.update(
                    kind="started",
                    scheduled_id=value.scheduled_event_id,
                    attempt=value.attempt,
                )
            else:
                kinds = {
                    EventType.EVENT_TYPE_ACTIVITY_TASK_COMPLETED: (
                        "completed",
                        "activity_task_completed_event_attributes",
                    ),
                    EventType.EVENT_TYPE_ACTIVITY_TASK_FAILED: (
                        "failed",
                        "activity_task_failed_event_attributes",
                    ),
                    EventType.EVENT_TYPE_ACTIVITY_TASK_TIMED_OUT: (
                        "timed_out",
                        "activity_task_timed_out_event_attributes",
                    ),
                    EventType.EVENT_TYPE_ACTIVITY_TASK_CANCELED: (
                        "cancelled",
                        "activity_task_canceled_event_attributes",
                    ),
                }
                if event.event_type not in kinds:
                    continue
                kind, attribute = kinds[event.event_type]
                row.update(
                    kind=kind, scheduled_id=getattr(event, attribute).scheduled_event_id
                )
            rows.append(row)
        return workflow_id, activity_metrics(rows)

    return dict(await asyncio.gather(*(one(identity) for identity in ids)))


if __name__ == "__main__":
    ids = json.load(sys.stdin)
    if (
        not isinstance(ids, list)
        or not 1 <= len(ids) <= 100
        or not all(isinstance(i, str) and i.startswith("ingest-source-") for i in ids)
    ):
        raise SystemExit(
            "Only explicitly supplied ingestion workflow IDs are supported"
        )
    print(json.dumps(asyncio.run(collect(ids))))
