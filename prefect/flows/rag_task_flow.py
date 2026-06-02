from __future__ import annotations

import argparse
import os
import sys
from pathlib import Path
from typing import Any, Optional

try:
    from prefect import flow, get_run_logger, task
except ImportError:  # Allows local syntax checks without Prefect installed.
    def flow(fn=None, **_kwargs):
        return fn if fn is not None else lambda wrapped: wrapped

    def task(fn=None, **_kwargs):
        return fn if fn is not None else lambda wrapped: wrapped

    class _Logger:
        def info(self, message: str, *args: Any) -> None:
            print(message % args if args else message)

        warning = info
        error = info

    def get_run_logger() -> _Logger:
        return _Logger()


sys.path.append(str(Path(__file__).resolve().parents[1]))

from orchestrator import LaravelClient, client_from_env, wait_seconds


TERMINAL_TASK_STATUSES = {"completed", "failed", "cancelled"}


@task
def load_task(client: LaravelClient, task_id: str) -> dict[str, Any]:
    return client.get_task(task_id)


@task
def guarded_complete_if_idle(client: LaravelClient, task_id: str) -> dict[str, Any]:
    return client.complete_if_idle(task_id)


@task
def mark_cancelled(client: LaravelClient, task_id: str) -> dict[str, Any]:
    return client.update_status(
        task_id,
        "cancelled",
        {"prefect": {"event": "cancelled_by_prefect"}},
    )


@flow(name="rag_task_flow")
def rag_task_flow(
    task_id: str,
    laravel_base_url: Optional[str] = None,
    api_token: Optional[str] = None,
    poll_interval_seconds: int = 10,
    max_idle_seconds: int = 3600,
) -> dict[str, Any]:
    logger = get_run_logger()
    client = client_from_env(laravel_base_url, api_token)
    idle_seconds = 0

    logger.info("Starting Prefect supervisor for Laravel pipeline task %s", task_id)

    while True:
        payload = load_task(client, task_id)
        task_payload = payload.get("task", {})
        status = str(task_payload.get("status", "unknown"))
        counters = task_payload.get("counters", {})
        active_jobs = int(task_payload.get("activeJobs") or counters.get("jobs_active") or 0)

        logger.info("Task %s status=%s active_jobs=%s", task_id, status, active_jobs)

        if status in TERMINAL_TASK_STATUSES:
            return task_payload

        if status == "cancel_requested":
            result = mark_cancelled(client, task_id)
            return result.get("task", result)

        if status == "paused":
            wait_seconds(poll_interval_seconds)
            continue

        if active_jobs == 0:
            completion = guarded_complete_if_idle(client, task_id)
            completed_task = completion.get("task", {})
            completed_status = str(completed_task.get("status", "unknown"))
            if completed_status in TERMINAL_TASK_STATUSES:
                return completed_task

            idle_seconds += poll_interval_seconds
            if idle_seconds >= max_idle_seconds:
                logger.warning("Task %s stayed idle for %s seconds without terminal state.", task_id, idle_seconds)
                return completed_task
        else:
            idle_seconds = 0

        wait_seconds(poll_interval_seconds)


def main() -> None:
    parser = argparse.ArgumentParser(description="Run the HAWKI RAG Prefect task supervisor.")
    parser.add_argument("--task-id", required=True)
    parser.add_argument("--laravel-base-url", default=os.getenv("LARAVEL_BASE_URL"))
    parser.add_argument("--api-token", default=os.getenv("LARAVEL_API_TOKEN", ""))
    parser.add_argument("--poll-interval-seconds", type=int, default=int(os.getenv("PREFECT_TASK_POLL_INTERVAL_SECONDS", "10")))
    parser.add_argument("--max-idle-seconds", type=int, default=int(os.getenv("PREFECT_TASK_MAX_IDLE_SECONDS", "3600")))
    args = parser.parse_args()

    rag_task_flow(
        task_id=args.task_id,
        laravel_base_url=args.laravel_base_url,
        api_token=args.api_token,
        poll_interval_seconds=args.poll_interval_seconds,
        max_idle_seconds=args.max_idle_seconds,
    )


if __name__ == "__main__":
    main()
