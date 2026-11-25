"""Measure a ten-PDF multipart burst, worker queues and client latency.

Uses a local service JWT and disposable datasets.
Temporal history is read through docker compose exec after completion.
"""

import argparse
import asyncio
from datetime import datetime, timezone
import hashlib
import json
import math
from pathlib import Path
import subprocess
import time
from uuid import uuid4

import httpx

from local_api_client import api_url, service_headers

from benchmark_local_ingestion import synthetic_pdf


def distribution(values):
    values = sorted(values)
    if not values:
        return None
    return {
        "min": round(values[0], 3),
        "p50": round(values[math.ceil(0.5 * len(values)) - 1], 3),
        "p95": round(values[math.ceil(0.95 * len(values)) - 1], 3),
        "max": round(values[-1], 3),
        "samples": len(values),
    }


def peak_overlap(activities):
    points = []
    for row in activities:
        points.extend([(row["started_ns"], 1), (row["finished_ns"], -1)])
    active = peak = 0
    for _, change in sorted(points):
        active += change
        peak = max(peak, active)
    return peak


def worker_configuration():
    """Read only effective thread counts/queue names, never the environment."""
    configurations = {}
    workers = {
        "indexer": (
            "hawki_indexer_worker.settings",
            "IndexerSettings",
            "from_env",
            "s",
        ),
        "converter": (
            "hawki_converter_worker.settings",
            "ConverterSettings",
            "from_env",
            "s",
        ),
        "scraper": (
            "hawki_scraper_worker.settings",
            "ScraperWorkerSettings",
            "from_environment",
            "s.runtime",
        ),
    }
    for role, (module, settings, factory, value) in workers.items():
        code = (
            f"import json; from {module} import {settings}; s={settings}.{factory}(); "
            f"print(json.dumps({{'activity_worker_threads':{value}.activity_worker_threads,'queue':s.task_queue}}))"
        )
        result = subprocess.run(
            [
                "docker",
                "compose",
                "exec",
                "-T",
                f"{role}_worker",
                "python",
                "-c",
                code,
            ],
            capture_output=True,
            text=True,
            timeout=30,
        )
        if result.returncode:
            raise RuntimeError("Worker configuration read failed: " + role)
        configurations[role] = json.loads(result.stdout)
    return configurations


def worker_handoff_delay(item, activity):
    """Scheduled-to-running callback span, including SDK/executor handoff.

    Unlike Temporal schedule-to-start this includes worker-side waiting and
    callback preparation. Clocks are UTC on the local stack; missing or
    inconsistent timestamps are left unmeasured.
    """
    events = [
        event
        for event in item.get("events", [])
        if event.get("stage") == "ingest" and event.get("status") == "running"
    ]
    if not events or not activity:
        return None
    started_ns = min(
        round(datetime.fromisoformat(e["timestamp"]).timestamp() * 1e9) for e in events
    )
    delay = (started_ns - activity["scheduled_ns"]) / 1e6
    return round(delay, 3) if delay >= 0 else None


def render(report):
    lines = [
        f"# {report['count']}-PDF queue and latency benchmark",
        "",
        f"Started: {report['started_at']}",
        "",
        f"One multipart `POST /documents/batch` per mode, containing {report['count']} distinct synthetic PDFs "
        "(approximately 3 MB and six searchable pages each). Unique document text avoids identical-content "
        "deduplication. Most bytes are an uncompressed illustration; this is not an OCR/dense-document capacity estimate.",
        "",
        "All times below are milliseconds. Client completion is observed by polling, so includes up to one poll interval "
        "plus polling-request latency. Temporal schedule-to-start is server history time, not an inferred client wait. "
        "Activity execution includes any worker executor/model waiting after Temporal starts the activity. "
        "Retries can make schedule-to-start include backoff; attempts are recorded. "
        "Scheduled-to-running callback spans also include SDK/executor handoff and callback preparation; "
        "they compare UTC clocks on this local stack and are not pure Temporal queue time.",
        "",
        f"Monitoring mode: `{report.get('polling_mode', 'parallel')}`. List mode uses one task-list request per polling cycle; parallel mode issues one request per pending task.",
        "",
        "One sequential batch per mode; hardware, warm caches, model contention and other traffic affect results. "
        "Percentiles use nearest rank: for ten documents p95 is the slowest document, not a stable service-level estimate.",
        "",
        "| Mode | Result | Accepted | Batch response | First ready | Last ready | Ready p50 | Ready p95 | PDFs/min |",
        "|---|---|---:|---:|---:|---:|---:|---:|---:|",
    ]
    for run in report["runs"]:
        summary = run.get("summary", {})
        lines.append(
            "| "
            + " | ".join(
                str(v)
                for v in [
                    run["mode"],
                    run["status"],
                    run.get("accepted", "—"),
                    run.get("admission_ms", "—"),
                    summary.get("first_ready_ms", "—"),
                    summary.get("last_ready_ms", "—"),
                    (summary.get("ready_ms") or {}).get("p50", "—"),
                    (summary.get("ready_ms") or {}).get("p95", "—"),
                    summary.get("pdfs_per_minute", "—"),
                ]
            )
            + " |"
        )
    for run in report["runs"]:
        lines += [
            "",
            "## " + run["mode"],
            "",
            "| PDF | Status | Ready since submission (ms) | Convert queue (ms) | Index queue (ms) | Index scheduled to running callback (ms) | Index execution (ms) |",
            "|---|---|---:|---:|---:|---:|---:|",
        ]
        for item in run.get("documents", []):
            activities = item.get("activities", [])
            convert = next(
                (a for a in activities if "convert" in a["activity"].lower()), {}
            )
            index = next(
                (
                    a
                    for a in activities
                    if "ingest_markdown" in a["activity"].lower()
                    or "index_markdown" in a["activity"].lower()
                ),
                {},
            )
            lines.append(
                "| "
                + " | ".join(
                    ("—" if v is None else str(v))
                    for v in [
                        item["number"],
                        item.get("status", "—"),
                        item.get("ready_ms", "—"),
                        convert.get("schedule_to_start_ms", "—"),
                        index.get("schedule_to_start_ms", "—"),
                        worker_handoff_delay(item, index),
                        index.get("execution_ms", "—"),
                    ]
                )
                + " |"
            )
        summary = run.get("summary", {})
        lines += [
            "",
            f"Task-list GET probe latency distribution (ms): `{json.dumps(summary.get('probe_ms'))}`; HTTP failures: {run.get('probe_failures', 0)}.",
            f"Temporal activity schedule-to-start distributions: `{json.dumps(summary.get('queues_ms'))}`.",
            f"Peak Temporal started-but-not-finished activity counts: `{json.dumps(summary.get('peak_started_by_activity'))}`. These counts are not CPU-thread or LLM concurrency.",
            f"Cleanup: **{run.get('cleanup', 'not completed')}**. Retry attempts above one: {summary.get('retried_activities', 'unmeasured')}.",
        ]
        if run.get("error"):
            lines += ["", "Failure: " + run["error"]]
    lines += [
        "",
        "Runtime model configuration: `" + json.dumps(report.get("runtime", {})) + "`.",
        "",
        "Measured worker settings: `" + json.dumps(report.get("workers", {})) + "`.",
        "",
        "The batch handler admits files sequentially and launches independent Temporal workflows. Conversion and indexing use different queues. "
        "An accepted upload is not a completed ingestion, and completion order is not guaranteed. "
        "Graph extraction can saturate model capacity while API requests remain responsive.",
        "",
        "Reproduce: `python_backend/.venv/bin/python scripts/benchmark_local_pdf_queue.py`.",
        f"Detailed measurements: `{report['json_report']}`.",
        "",
    ]
    return "\n".join(lines)


async def benchmark(args, report, save):
    report["workers"] = await asyncio.to_thread(worker_configuration)
    pdfs = [
        (number, *synthetic_pdf("Q" + uuid4().hex[:6]))
        for number in range(1, args.count + 1)
    ]
    report["inputs"] = [
        {
            "number": n,
            "bytes": len(pdf),
            "sha256": hashlib.sha256(pdf).hexdigest(),
            "text_characters": len(text),
        }
        for n, pdf, text in pdfs
    ]
    async with httpx.AsyncClient(
        timeout=240,
        trust_env=False,
        follow_redirects=False,
        limits=httpx.Limits(max_connections=30),
        base_url=api_url(),
        headers=service_headers(),
    ) as client:

        async def request(method, path, **kwargs):
            response = await client.request(method, path, **kwargs)
            if response.status_code not in {200, 201, 202, 207}:
                raise RuntimeError(f"{method} {path}: HTTP {response.status_code}")
            return response.json()

        async def retire(path, key):
            deadline = time.monotonic() + 600
            while time.monotonic() < deadline:
                response = await client.delete(path, headers={"Idempotency-Key": key})
                if response.status_code == 200:
                    return
                if response.status_code != 202:
                    raise RuntimeError(f"Cleanup HTTP {response.status_code}")
                await asyncio.sleep(2)
            raise TimeoutError("Cleanup pending")

        config = await request("GET", "/ops/config")
        report["runtime"] = {
            k: config.get("runtime", {}).get(k)
            for k in ("engine", "query_provider", "query_model", "embedding_model")
        }
        for graph in (False, True):
            run = {
                "mode": "graph_on" if graph else "graph_off",
                "dataset_id": "queue-bench-" + uuid4().hex[:16],
                "status": "RUNNING",
                "documents": [],
                "samples": [],
                "probe_failures": 0,
            }
            report["runs"].append(run)
            save()
            dataset = run["dataset_id"]
            created = False
            try:
                creation = await request(
                    "POST", "/datasets", json={"dataset_id": dataset}
                )
                created = True
                await request(
                    "POST",
                    f"/datasets/{dataset}/ingest-grants/self",
                    json={"grant_token": creation["grant_token"]},
                )
                start = time.monotonic()
                admission = await request(
                    "POST",
                    "/documents/batch",
                    headers={"Idempotency-Key": dataset},
                    data={
                        "dataset_id": dataset,
                        "graph": str(graph).lower(),
                        "converter_mode": "native",
                    },
                    files=[
                        ("files", (f"queue-{n:02}.pdf", pdf, "application/pdf"))
                        for n, pdf, _ in pdfs
                    ],
                )
                run["admission_ms"] = round((time.monotonic() - start) * 1000, 3)
                run["accepted"] = admission["summary"]["accepted"]
                for item in admission["items"]:
                    if item["success"]:
                        body = item["result"]
                        run["documents"].append(
                            {
                                "number": item["index"] + 1,
                                "document_id": body["document"]["document_id"],
                                "task_id": body["pipeline"]["task_id"],
                                "status": "accepted",
                            }
                        )
                save()
                if run["accepted"] != args.count:
                    raise RuntimeError(
                        "Some uploads rejected; inspect admission summary"
                    )

                async def poll(item, tasks=None):
                    task = (
                        tasks[item["task_id"]]
                        if tasks is not None
                        else (
                            await request("GET", "/pipeline/tasks/" + item["task_id"])
                        )["task"]
                    )
                    item["status"] = task["status"]
                    if task["status"] == "completed" and "ready_ms" not in item:
                        assert task["jobs"] and all(
                            j["index_status"] == "ready" for j in task["jobs"]
                        )
                        item["ready_ms"] = round((time.monotonic() - start) * 1000, 3)
                        item["workflow_id"] = task["jobs"][0]["temporal_workflow_id"]
                        item["events"] = (
                            await request(
                                "GET", f"/pipeline/tasks/{item['task_id']}/events"
                            )
                        )["events"]
                    return task["status"]

                while time.monotonic() - start < args.timeout:
                    pending = [d for d in run["documents"] if "ready_ms" not in d]
                    if args.polling == "parallel":
                        statuses = await asyncio.gather(*(poll(d) for d in pending))
                    else:
                        listing = await request("GET", "/pipeline/tasks?limit=100")
                        tasks = {t["task_id"]: t for t in listing["tasks"]}
                        statuses = [await poll(d, tasks) for d in pending]
                    probe = time.monotonic()
                    response = await client.get("/pipeline/tasks?limit=1")
                    probe_ms = (time.monotonic() - probe) * 1000
                    run["probe_failures"] += int(response.status_code != 200)
                    sample = {
                        "elapsed_ms": round((time.monotonic() - start) * 1000, 3),
                        "completed": sum("ready_ms" in d for d in run["documents"]),
                        "task_status_counts": {
                            s: sum(d["status"] == s for d in run["documents"])
                            for s in (
                                "accepted",
                                "pending",
                                "queued",
                                "running",
                                "completed",
                                "failed",
                            )
                        },
                        "probe_ms": round(probe_ms, 3),
                    }
                    run["samples"].append(sample)
                    save()
                    if (
                        len(run["samples"]) == 1
                        or sample["completed"] != run["samples"][-2]["completed"]
                        or len(run["samples"]) % 15 == 0
                    ):
                        print(json.dumps({"mode": run["mode"], **sample}), flush=True)
                    if any(s in {"failed", "cancelled"} for s in statuses):
                        raise RuntimeError("Pipeline failed or cancelled")
                    if all("ready_ms" in d for d in run["documents"]):
                        break
                    await asyncio.sleep(args.poll)
                else:
                    raise TimeoutError("Batch ingestion deadline exceeded")
                ids = [d["workflow_id"] for d in run["documents"]]
                helper = (
                    Path(__file__)
                    .with_name("benchmark_temporal_history.py")
                    .read_text()
                )

                def history():
                    result = subprocess.run(
                        [
                            "docker",
                            "compose",
                            "exec",
                            "-T",
                            "rag_backend",
                            "python",
                            "-c",
                            helper,
                        ],
                        input=json.dumps(ids),
                        text=True,
                        capture_output=True,
                        timeout=120,
                    )
                    if result.returncode:
                        raise RuntimeError("Temporal history collection failed")
                    return json.loads(result.stdout)

                histories = await asyncio.to_thread(history)
                for item in run["documents"]:
                    item["activities"] = histories[item["workflow_id"]]
                activities = [a for d in run["documents"] for a in d["activities"]]
                if not activities:
                    raise RuntimeError("No Temporal activity timing evidence")
                names = sorted({a["activity"] for a in activities})
                values = [d["ready_ms"] for d in run["documents"]]
                run["summary"] = {
                    "first_ready_ms": min(values),
                    "last_ready_ms": max(values),
                    "ready_ms": distribution(values),
                    "pdfs_per_minute": round(args.count * 60000 / max(values), 3),
                    "probe_ms": distribution([s["probe_ms"] for s in run["samples"]]),
                    "queues_ms": {
                        n: distribution(
                            [
                                a["schedule_to_start_ms"]
                                for a in activities
                                if a["activity"] == n
                            ]
                        )
                        for n in names
                    },
                    "peak_started_by_activity": {
                        n: peak_overlap([a for a in activities if a["activity"] == n])
                        for n in names
                    },
                    "retried_activities": sum(a["attempt"] > 1 for a in activities),
                }
                await request("POST", f"/datasets/{dataset}/query-grants/self", json={})
                listing = (
                    await request(
                        "GET",
                        "/documents",
                        params={"dataset_id": dataset, "limit": 100},
                    )
                )["documents"]
                indexed = {
                    d["document_id"] for d in listing if d["status"] == "indexed"
                }
                assert all(d["document_id"] in indexed for d in run["documents"])
                for item in run["documents"]:
                    response = await client.get(
                        "/documents/uploads/download",
                        params={"document_id": item["document_id"]},
                    )
                    assert (
                        response.status_code == 200
                        and response.content == pdfs[item["number"] - 1][1]
                    )
                overview = await request(
                    "GET", "/ops/graph/overview", params={"dataset_id": dataset}
                )
                run["graph"] = {
                    "nodes": len(overview["nodes"]),
                    "edges": len(overview["edges"]),
                }
                if graph:
                    assert overview["nodes"] and overview["edges"]
                else:
                    assert not overview["nodes"] and not overview["edges"]
                query = await request(
                    "POST",
                    "/query",
                    json={
                        "dataset_id": dataset,
                        "query": "Aurora Institute greenhouse irrigation measurements",
                        "top_k": 5,
                        "generate": False,
                        "fast_mode": False,
                    },
                )
                assert query["hits"]
                run["verification"] = "PASS"
                run["status"] = "PASS"
            except Exception as error:
                run["status"] = "FAIL"
                run["error"] = str(error)
            finally:
                cleanup = time.monotonic()
                errors = []
                for item in run["documents"]:
                    try:
                        await retire(
                            "/documents/" + item["document_id"],
                            dataset + "-delete-" + str(item["number"]),
                        )
                    except Exception as error:
                        errors.append(str(error))
                if created:
                    try:
                        await retire(
                            "/datasets/" + dataset + "/storage", dataset + "-retire"
                        )
                    except Exception as error:
                        errors.append(str(error))
                run["cleanup"] = "FAIL" if errors else "PASS"
                run["cleanup_ms"] = round((time.monotonic() - cleanup) * 1000, 3)
                if errors:
                    run["status"] = "FAIL"
                    run["cleanup_errors"] = errors
                save()
                print(
                    json.dumps(
                        {
                            k: v
                            for k, v in run.items()
                            if k not in {"documents", "samples"}
                        }
                    ),
                    flush=True,
                )
            if run["status"] != "PASS":
                raise SystemExit(1)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--count", type=int, default=10)
    parser.add_argument("--polling", choices=("list", "parallel"), default="list")
    parser.add_argument("--timeout", type=int, default=3600)
    parser.add_argument("--poll", type=float, default=2)
    parser.add_argument(
        "--report", type=Path, default=Path("/tmp/rawki-pdf-queue-benchmark.json")
    )
    parser.add_argument(
        "--markdown", type=Path, default=Path("/tmp/rawki-pdf-queue-benchmark.md")
    )
    args = parser.parse_args()
    if not 1 <= args.count <= 20 or args.timeout <= 0 or args.poll <= 0:
        parser.error("count must be 1..20; timeout and poll must be positive")
    report = {
        "started_at": datetime.now(timezone.utc).isoformat(),
        "json_report": str(args.report),
        "count": args.count,
        "polling_mode": args.polling,
        "runs": [],
    }

    def save():
        args.report.write_text(json.dumps(report, indent=2) + "\n")
        args.markdown.write_text(render(report))

    asyncio.run(benchmark(args, report, save))


if __name__ == "__main__":
    main()
