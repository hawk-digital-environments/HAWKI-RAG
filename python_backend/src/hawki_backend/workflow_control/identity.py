"""Authoritative identities, matching the existing persisted Laravel projections."""

import hashlib
import re


def source_workflow(source_id: str) -> str:
    return "ingest-source-" + source_id


def text_workflow(task_id: str) -> str:
    return "ingest-text-" + hashlib.sha256(task_id.encode()).hexdigest()[:32]


def source_schedule(source_id: str) -> str:
    return "schedule-" + source_workflow(source_id)


def source_retry(
    source_id: str, previous: str | None, attempt: int, job_id: str
) -> str:
    base = previous or source_workflow(source_id)
    validate_source_workflow(source_id, base)
    safe_job = re.sub(r"[^A-Za-z0-9_.-]+", "-", job_id) or "job"
    return f"{base}-retry-{max(1, attempt)}-{safe_job}"


def validate_source_workflow(source_id: str, value: str) -> None:
    base = source_workflow(source_id)
    if (
        value != base
        and re.fullmatch(
            re.escape(base) + r"(?:-retry-[1-9][0-9]*-[A-Za-z0-9_.-]+)+", value
        )
        is None
    ):
        raise ValueError("Workflow identity differs from the prepared source.")


def validate_owned_workflow(value: str) -> None:
    if not value.startswith(("ingest-source-", "ingest-text-", "managed-delete_")):
        raise ValueError("Unrecognized prepared workflow identity.")
