"""Authorized URL pipeline projections; no Laravel mirror rows or raw file reads."""

import json
from datetime import datetime

import sqlalchemy as sa

from .errors import UrlIngestionError
from .ownership import classify
from .schema import requests, generations, events


def allowed(service, principal, dataset):
    try:
        service.authorize(principal, dataset)
        return True
    except UrlIngestionError as error:
        if error.status == 404:
            return False
        raise


def tasks(service, principal, *, limit=30):
    with service.repository.engine.connect() as connection:
        rows = list(
            connection.execute(
                sa.select(requests)
                .where(requests.c.tenant_id == principal.tenant_id)
                .order_by(requests.c.created_at.desc(), requests.c.task_id)
            ).mappings()
        )
    result = []
    for row in rows:
        if allowed(service, principal, row["dataset_id"]):
            classify(service.repository, {"task_id": row["task_id"]})
            result.append(service.task_payload(principal, row["task_id"])["task"])
            if len(result) == limit:
                break
    result.extend(other_tasks(service, principal))
    result.sort(
        key=lambda item: (
            -datetime.fromisoformat(item["started_at"]).timestamp(),
            item["task_id"],
        )
    )
    return {"success": True, "tasks": result[:limit]}


def jobs(service, principal, filters):
    with service.repository.engine.connect() as connection:
        rows = list(
            connection.execute(
                sa.select(generations).where(
                    generations.c.tenant_id == principal.tenant_id,
                    generations.c.purpose == "start",
                )
            ).mappings()
        )
    result = []
    for row in rows:
        if any(
            filters.get(field) and row[field] != filters[field]
            for field in ("task_id", "dataset_id")
        ):
            continue
        if not allowed(service, principal, row["dataset_id"]):
            continue
        classify(service.repository, {"job_id": row["job_id"]})
        task = service.task_payload(principal, row["task_id"])["task"]
        job = next(job for job in task["jobs"] if job["job_id"] == row["job_id"])
        if job["status"] != "failed":
            continue
        result.append(
            job
            | {
                "owner": "url",
                "dataset_id": row["dataset_id"],
                "timestamp": (row.get("completion") or {}).get("occurred_at")
                or row["updated_at"].isoformat(),
            }
        )
    for task in other_tasks(service, principal):
        for job in task["jobs"]:
            if job["status"] == "failed" and all(
                not filters.get(field) or job[field] == filters[field]
                for field in ("task_id", "dataset_id")
            ):
                result.append(job)
    result.sort(
        key=lambda job: (
            -datetime.fromisoformat(job["timestamp"]).timestamp(),
            job["job_id"],
        )
    )
    return {"success": True, "jobs": result[: filters.get("limit", 100)]}


def timeline(service, principal, task_id, filters):
    payload = task(service, principal, task_id)["task"]
    operations = [
        job["url_ingestion_operation_id"]
        for job in payload["jobs"]
        if "url_ingestion_operation_id" in job
    ]
    with service.repository.engine.connect() as connection:
        rows = list(
            connection.execute(
                sa.select(events).where(
                    events.c.url_ingestion_operation_id.in_(operations),
                    events.c.kind == "completion",
                )
            ).mappings()
        )
    result = []
    for row in rows:
        receipt = row["receipt"]
        if receipt.get("ignored"):
            continue
        item = {
            key: receipt.get(key)
            for key in (
                "event_id",
                "event_type",
                "stage",
                "phase",
                "status",
                "counts",
                "job_id",
                "workflow_id",
                "attempt",
                "errors",
            )
        }
        item["at"] = receipt["timestamp"]
        result.append(item)
    result.sort(
        key=lambda item: (
            datetime.fromisoformat(item["at"]).timestamp(),
            item["event_id"],
        )
    )
    available = {
        "event_types": sorted({item["event_type"] for item in result}),
        "job_ids": sorted({item["job_id"] for item in result if item["job_id"]}),
    }
    result = [
        item
        for item in result
        if all(
            not filters.get(field) or item.get(field) == filters[field]
            for field in ("event_type", "job_id", "stage")
        )
    ]
    return {
        "success": True,
        "task_id": task_id,
        "events": result[: filters.get("limit", 50)],
        "filters": available,
    }


def logs(service, principal, task_id, stage):
    stage = {
        "scraper": "scrape",
        "scrape": "scrape",
        "converter": "convert",
        "convert": "convert",
        "ingestion": "ingest",
        "ingest": "ingest",
    }.get(stage)
    if stage is None:
        raise UrlIngestionError(422, "Unsupported pipeline stage.")
    payload = timeline(service, principal, task_id, {"stage": stage, "limit": 5000})
    lines = [json.dumps(item, sort_keys=True) for item in payload["events"]]
    return {
        "success": True,
        "task_id": task_id,
        "log": {
            "stage": stage,
            "label": stage.title(),
            "filename": stage + "_log_" + task_id + ".txt",
            "text": "\n".join(lines),
            "line_count": len(lines),
            "updated_at": payload["events"][-1]["at"] if lines else None,
        },
    }


def status(service, principal, job_id):
    classify(service.repository, {"job_id": job_id})
    with service.repository.engine.connect() as connection:
        row = (
            connection.execute(
                sa.select(generations).where(
                    generations.c.tenant_id == principal.tenant_id,
                    generations.c.job_id == job_id,
                )
            )
            .mappings()
            .one_or_none()
        )
    if row is None:
        candidates = [
            item
            for item in other_tasks(service, principal)
            if any(job["job_id"] == job_id for job in item["jobs"])
        ]
        if len(candidates) != 1:
            raise UrlIngestionError(404, "Pipeline job was not found.")
        task_payload = candidates[0]
    else:
        task_payload = service.task_payload(principal, row["task_id"])["task"]
    job = next(job for job in task_payload["jobs"] if job["job_id"] == job_id)
    history = timeline(
        service, principal, task_payload["task_id"], {"job_id": job_id, "limit": None}
    )["events"]
    stages = {
        name: {"status": "not_tracked", "message": "No worker event received."}
        for name in ("scrape", "convert", "ingest")
    }
    for event in history:
        stages[event["stage"]] = {
            "status": event["status"],
            "counts": event["counts"],
            "updated_at": event["at"],
        }
    return {
        "success": True,
        "job_id": job_id,
        "status": job["status"],
        "stages": stages,
        "current_stage": history[-1]["stage"] if history else None,
        "managed_documents": [],
        "managed_document_count": 0,
        "source": task_payload["sources"][0],
    }


def other_tasks(service, principal):
    """Read existing Epoch 9/10 authority through the pipeline UI contract."""
    from hawki_backend.managed_documents.schema import revisions
    from hawki_backend.text_ingestion.schema import ingest_text_operations

    result = []
    with service.repository.engine.connect() as connection:
        for owner, table in (("managed", revisions), ("text", ingest_text_operations)):
            for stored in connection.execute(
                sa.select(table).where(table.c.tenant_id == principal.tenant_id)
            ).mappings():
                row = dict(stored)
                if not allowed(service, principal, row["dataset_id"]):
                    continue
                state = row.get("status", row.get("lifecycle_status"))
                classify(service.repository, {"task_id": row["task_id"]})
                classify(service.repository, {"job_id": row["job_id"]})
                failed = state in {"failed", "delete_failed"} or (
                    row.get("completion") or {}
                ).get("status") in {"failed", "skipped"}
                completed = not failed and state in {"ready", "cleaned", "deleted"}
                status_value = (
                    "completed" if completed else "failed" if failed else "running"
                )
                wire = row.get("workflow_input") or row.get("request_metadata", {}).get(
                    "workflow_input", {}
                )
                job = {
                    "owner": owner,
                    "job_id": row["job_id"],
                    "task_id": row["task_id"],
                    "dataset_id": row["dataset_id"],
                    "source_id": row["source_id"],
                    "source_url": wire.get("source_url"),
                    "job_type": "ingest",
                    "status": status_value,
                    "index_status": "ready" if completed else None,
                    "temporal_workflow_id": row["workflow_id"],
                    "temporal_run_id": row["run_id"],
                    "temporal_schedule_id": None,
                    "timestamp": (row.get("completion") or {}).get("occurred_at")
                    or row["updated_at"].isoformat(),
                }
                task = {
                    "owner": owner,
                    "task_id": row["task_id"],
                    "dataset_id": row["dataset_id"],
                    "jobs": [job],
                    "status": status_value,
                    "total_jobs": 1,
                    "active_jobs": int(status_value == "running"),
                    "started_at": row["created_at"].isoformat(),
                    "updated_at": row["updated_at"].isoformat(),
                    "finished_at": row["updated_at"].isoformat()
                    if completed or failed
                    else None,
                    "metadata": {},
                    "managed_documents": [],
                    "managed_document_count": 0,
                    "sources": [
                        {"source_id": row["source_id"], "dataset_id": row["dataset_id"]}
                    ],
                    "stages": [],
                    "counters": {
                        "jobs_total": 1,
                        "jobs_running": int(status_value == "running"),
                        "jobs_completed": int(completed),
                        "jobs_failed": int(failed),
                        "jobs_skipped": 0,
                        "total": 1,
                        "completed": int(completed),
                        "failed": int(failed),
                    },
                }
                result.append(task)
    return result


def task(service, principal, task_id):
    owner = classify(service.repository, {"task_id": task_id})
    if owner == "url":
        return service.task_payload(principal, task_id)
    candidates = [
        item for item in other_tasks(service, principal) if item["task_id"] == task_id
    ]
    if len(candidates) != 1:
        raise UrlIngestionError(404, "Pipeline task was not found.")
    return {"success": True, "task_id": task_id, "task": candidates[0]}


def stage_projection(service, children):
    """Aggregate the newest signed stage evidence for every job in a URL task."""
    ids = {row["url_ingestion_operation_id"]: row["job_id"] for row in children}
    with service.repository.engine.connect() as connection:
        rows = list(
            connection.execute(
                sa.select(events).where(
                    events.c.url_ingestion_operation_id.in_(ids),
                    events.c.kind == "completion",
                )
            ).mappings()
        )
    latest = {}
    for row in sorted(
        rows,
        key=lambda item: (
            datetime.fromisoformat(item["receipt"]["timestamp"]).timestamp(),
            item["event_id"],
        ),
    ):
        receipt = row["receipt"]
        if receipt.get("ignored") or not receipt.get("status"):
            continue
        latest[(ids[row["url_ingestion_operation_id"]], receipt["stage"])] = receipt
    result = {}
    for stage in ("scrape", "convert", "ingest"):
        evidence = [value for (_, name), value in latest.items() if name == stage]
        if not evidence:
            continue
        statuses = [value["status"] for value in evidence]
        status = (
            "failed"
            if "failed" in statuses
            else "running"
            if "running" in statuses
            else "queued"
            if len(evidence) < len(children)
            else "completed"
        )
        counts = {
            field: sum((value.get("counts") or {}).get(field, 0) for value in evidence)
            for field in ("total", "processed", "failed", "skipped")
        }
        counts["completed"] = counts["processed"]
        result[stage] = {
            "status": status,
            "counts": counts,
            "updated_at": max(
                evidence,
                key=lambda item: datetime.fromisoformat(item["timestamp"]).timestamp(),
            )["timestamp"],
        }
    return result
