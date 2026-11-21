"""Exercise the protected local URL API; never print credentials or grant tokens.

Requires the explicit cutover and crawler-smoke overlays, plus dedicated local
workers. New content belongs to one uniquely named disposable Python dataset.
"""

import json
from pathlib import Path
import time
from uuid import uuid4

import httpx

ROOT = Path(__file__).resolve().parents[1]
BASE = "http://localhost:8080/ui-api/v1"


def request(client: httpx.Client, method: str, path: str, **kwargs) -> dict:
    response = client.request(method, BASE + path, **kwargs)
    if response.status_code not in {200, 201, 202}:
        raise RuntimeError(f"{method} {path}: HTTP {response.status_code}")
    return response.json()


def wait_ready(client: httpx.Client, task_id: str, timeout: int = 300) -> dict:
    deadline = time.monotonic() + timeout
    previous = None
    while time.monotonic() < deadline:
        task = request(client, "GET", "/pipeline/tasks/" + task_id)["task"]
        state = task["status"]
        if state != previous:
            print(json.dumps({"task_id": task_id, "state": state}), flush=True)
            previous = state
        if state == "completed":
            assert all(job["index_status"] == "ready" for job in task["jobs"])
            return task
        if state == "failed":
            raise RuntimeError("URL task failed: " + task_id)
        time.sleep(2)
    raise TimeoutError("URL task did not become ready: " + task_id)


def retire(client: httpx.Client, path: str, key: str) -> dict:
    deadline = time.monotonic() + 120
    while time.monotonic() < deadline:
        response = client.delete(BASE + path, headers={"Idempotency-Key": key})
        if response.status_code == 200:
            return response.json()
        if response.status_code != 202:
            raise RuntimeError(f"DELETE {path}: HTTP {response.status_code}")
        time.sleep(2)
    raise TimeoutError("Retirement remains pending: " + path)


def main() -> None:
    run = "crawler-v2-" + uuid4().hex[:12]
    result_path = Path("/tmp/rawki-crawler-v2-url-smoke.json")
    summary = {"run": run, "tasks": [], "checks": {}}
    operator = (ROOT / ".secrets/ui-service/operator.secret").read_text().strip()
    with httpx.Client(
        auth=("local-management", operator),
        timeout=180,
        trust_env=False,
        headers={"Origin": "http://localhost:8080", "X-Hawki-Browser": "1"},
    ) as client:
        creation = request(
            client,
            "POST",
            "/datasets",
            json={"dataset_id": run, "name": "Disposable crawler v2 smoke"},
        )
        summary["dataset_id"] = run
        result_path.write_text(json.dumps(summary, indent=2) + "\n")
        request(
            client,
            "POST",
            f"/datasets/{run}/ingest-grants/self",
            json={"grant_token": creation["grant_token"]},
        )
        options = {"max_pages": 1, "skip_images": True, "max_rpm": 60000}
        normal = {
            "dataset_id": run,
            "urls": ["http://93.184.216.34:8099/page"],
            "metadata": options,
        }
        first = request(
            client,
            "POST",
            "/pipeline/tasks/start",
            json=normal,
            headers={"Idempotency-Key": run + "-normal"},
        )
        summary["tasks"].append(first["task_id"])
        result_path.write_text(json.dumps(summary, indent=2) + "\n")
        ready = wait_ready(client, first["task_id"])
        summary["checks"]["html_ready"] = True
        summary["normal_jobs"] = ready["jobs"]
        replay = request(
            client,
            "POST",
            "/pipeline/tasks/start",
            json=normal,
            headers={"Idempotency-Key": run + "-normal"},
        )
        assert replay["task_id"] == first["task_id"]
        assert [job["job_id"] for job in replay["task"]["jobs"]] == [
            job["job_id"] for job in ready["jobs"]
        ]
        summary["checks"]["idempotent_start"] = True
        request(client, "POST", f"/datasets/{run}/query-grants/self", json={})
        visible = request(client, "GET", "/query/datasets")["datasets"]
        assert any(row["dataset_id"] == run for row in visible)
        summary["checks"]["indexed_dataset_query_visible"] = True
        sitemap = request(
            client,
            "POST",
            "/pipeline/tasks/start",
            json={
                "dataset_id": run,
                "sitemap_url": "http://93.184.216.34:8099/sitemap.xml",
                "metadata": options,
            },
            headers={"Idempotency-Key": run + "-sitemap"},
        )
        summary["tasks"].append(sitemap["task_id"])
        result_path.write_text(json.dumps(summary, indent=2) + "\n")
        summary["sitemap_jobs"] = wait_ready(client, sitemap["task_id"])["jobs"]
        summary["checks"]["sitemap_ready"] = True
        denied = client.post(
            BASE + "/pipeline/tasks/start",
            json={
                "dataset_id": run,
                "urls": ["http://169.254.169.254/latest/meta-data/"],
            },
        )
        assert denied.status_code == 422
        summary["checks"]["private_url_rejected"] = True
        slow = request(
            client,
            "POST",
            "/pipeline/tasks/start",
            json={
                "dataset_id": run,
                "urls": ["http://93.184.216.34:8099/slow"],
                "metadata": options,
            },
            headers={"Idempotency-Key": run + "-slow"},
        )
        summary["tasks"].append(slow["task_id"])
        summary["slow_jobs"] = slow["task"]["jobs"]
        result_path.write_text(json.dumps(summary, indent=2) + "\n")
        time.sleep(4)
        request(
            client,
            "POST",
            "/pipeline/tasks/" + slow["task_id"] + "/cancel",
            json={},
        )
        deadline = time.monotonic() + 120
        while True:
            response = request(client, "GET", "/pipeline/tasks/" + slow["task_id"])
            if response["task"]["status"] == "cancelled":
                break
            if time.monotonic() > deadline:
                raise TimeoutError("Cancellation did not drain")
            time.sleep(2)
        summary["checks"]["active_task_cancelled"] = True
        # Preserve terminal receipts for the separate read-only crawler audit.
        for task_id in summary["tasks"]:
            retire(client, "/pipeline/tasks/" + task_id, run + "-delete-" + task_id)
        summary["checks"]["task_retirement_complete"] = True
        result_path.write_text(json.dumps(summary, indent=2) + "\n")
        retire(client, "/datasets/" + run + "/storage", run + "-dataset-delete")
        summary["checks"]["dataset_retirement_complete"] = True
        summary["status"] = "PASS"
        result_path.write_text(json.dumps(summary, indent=2) + "\n")
        print(json.dumps(summary, indent=2), flush=True)


if __name__ == "__main__":
    main()
