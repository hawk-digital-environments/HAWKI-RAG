"""Live disposable graph acceptance through the authenticated local service API.

Uses real converter/indexer/model/store services. No credentials enter the report.
Run the separate read-only storage audit after this smoke completes.
"""

import hashlib
import json
from pathlib import Path
import time
from uuid import uuid4

import httpx

from local_api_client import api_url, service_headers

REPORT = Path("/tmp/rawki-orphan-graph-smoke.json")
CONTENT = b"""# Project Cedar acceptance fixture

Mira Vale leads Project Cedar at Aurora Institute. Aurora Institute operates the
Cedar Sensor in Berlin. Project Cedar uses Atlas Protocol to collect measurements.
The Cedar Sensor sends measurements to Atlas Gateway. Noah Reed maintains Atlas
Gateway and works with Mira Vale at Aurora Institute. Atlas Gateway stores
temperature measurements from Cedar Sensor. Project Cedar studies temperature
measurements to improve greenhouse irrigation. Aurora Institute owns the
greenhouse. Noah Reed designed Atlas Protocol for Project Cedar. Mira Vale
reviews the measurements and reports results to Aurora Institute every week.
"""


def main() -> None:
    run = "graph-accept-" + uuid4().hex[:12]
    summary = {"dataset_id": run, "checks": {}, "status": "RUNNING"}
    created = False
    documents = []
    text_sources = []

    def save() -> None:
        REPORT.write_text(json.dumps(summary, indent=2) + "\n")

    def check(name: str, value) -> None:
        summary["checks"][name] = value
        save()
        print(json.dumps({name: value}), flush=True)

    with httpx.Client(
        timeout=240,
        trust_env=False,
        base_url=api_url(),
        headers=service_headers(),
    ) as client:

        def request(method: str, path: str, **kwargs) -> dict:
            response = client.request(method, path, **kwargs)
            if response.status_code not in {200, 201, 202}:
                raise RuntimeError(f"{method} {path}: HTTP {response.status_code}")
            return response.json()

        def retire(path: str, key: str) -> None:
            deadline = time.monotonic() + 240
            while time.monotonic() < deadline:
                response = client.delete(path, headers={"Idempotency-Key": key})
                if response.status_code == 200:
                    return
                if response.status_code != 202:
                    raise RuntimeError(f"DELETE {path}: HTTP {response.status_code}")
                time.sleep(2)
            raise TimeoutError("Retirement pending: " + path)

        def wait_task(task_id: str) -> dict:
            deadline = time.monotonic() + 900
            previous = None
            while time.monotonic() < deadline:
                task = request("GET", "/pipeline/tasks/" + task_id)["task"]
                state = task["status"]
                if state != previous:
                    print(json.dumps({"task_id": task_id, "state": state}), flush=True)
                    previous = state
                if state == "completed":
                    assert all(job["index_status"] == "ready" for job in task["jobs"])
                    return task
                if state in {"failed", "cancelled"}:
                    raise RuntimeError("Ingestion failed: " + task_id)
                time.sleep(3)
            raise TimeoutError("Ingestion pending: " + task_id)

        try:
            creation = request("POST", "/datasets", json={"dataset_id": run})
            created = True
            save()
            request(
                "POST",
                f"/datasets/{run}/ingest-grants/self",
                json={"grant_token": creation["grant_token"]},
            )
            configured = request("GET", "/ops/config")["datasets"]
            assert next(row for row in configured if row["dataset_id"] == run)[
                "graph_enabled"
            ]
            check("server_graph_configuration", True)
            result = request(
                "POST",
                "/documents",
                headers={"Idempotency-Key": run + "-graph"},
                data={"dataset_id": run, "graph": "true"},
                files={"file": ("cedar.md", CONTENT, "text/markdown")},
            )
            document_id = result["document"]["document_id"]
            documents.append(document_id)
            summary["managed"] = result
            save()
            summary["graph_task"] = wait_task(result["pipeline"]["task_id"])
            check("converter_indexer_ready", True)
            request("POST", f"/datasets/{run}/query-grants/self", json={})
            document = request("GET", "/documents/" + document_id)["document"]
            assert document["status"] == "indexed" and document["graph_enabled"]
            summary["indexed_document"] = document
            save()
            graph = request("GET", "/ops/graph/overview", params={"dataset_id": run})
            assert graph["nodes"] and graph["edges"]
            check(
                "graph_overview",
                {"nodes": len(graph["nodes"]), "edges": len(graph["edges"])},
            )
            node = graph["nodes"][0]
            detail = request(
                "GET",
                "/ops/graph/node",
                params={"dataset_id": run, "node_id": node["id"]},
            )
            assert detail["nodes"]
            expanded = request(
                "POST",
                "/ops/graph/expand",
                json={
                    "dataset_id": run,
                    "node_id": node["id"],
                    "depth": 2,
                    "limit": 80,
                },
            )
            assert expanded["nodes"]
            searched = request(
                "GET", "/ops/graph/search", params={"dataset_id": run, "q": "Cedar"}
            )
            assert searched["results"]
            semantic = request(
                "GET",
                "/ops/graph/semantic-search",
                params={"dataset_id": run, "q": "Project Cedar uses Atlas Protocol"},
            )
            assert semantic["results"]
            check("graph_node_expand_search_semantic", True)
            query = request(
                "POST",
                "/query",
                json={
                    "dataset_id": run,
                    "query": "Who leads Project Cedar and what protocol does it use?",
                    "top_k": 5,
                    "generate": False,
                    "fast_mode": False,
                },
            )
            assert query["hits"] and query["kg"]
            check("query", {"hits": len(query["hits"]), "kg": len(query["kg"])})
            download = client.get(
                "/documents/uploads/download",
                params={"document_id": document_id},
            )
            assert download.status_code == 200 and download.content == CONTENT
            check("download_sha256", hashlib.sha256(download.content).hexdigest())
            text = request(
                "POST",
                "/text-ingestions",
                headers={"Idempotency-Key": run + "-text"},
                json={
                    "dataset_id": run,
                    "external_document_id": "acceptance-text",
                    "text": "Python owns this disposable acceptance content. " * 20,
                    "content_format": "plain_text",
                },
            )
            summary["text"] = text
            text_sources.append(text["source_id"])
            save()
            summary["text_task"] = wait_task(text["task_id"])
            check("text_ingestion_ready", True)
            summary["status"] = "PASS"
        except Exception as error:
            summary["status"] = "FAIL"
            summary["error"] = str(error)
            raise
        finally:
            # Preserve immutable receipts and deleted ownership rows, remove all content.
            cleanup_errors = []
            for path, key in [
                *(
                    ("/text-ingestions/" + source, run + "-delete-text")
                    for source in text_sources
                ),
                *(("/documents/" + doc, run + "-delete-document") for doc in documents),
            ]:
                try:
                    retire(path, key)
                except Exception as error:
                    cleanup_errors.append(str(error))
            if created:
                try:
                    empty = request(
                        "GET", "/ops/graph/overview", params={"dataset_id": run}
                    )
                    assert not empty["nodes"] and not empty["edges"]
                    check("graph_empty_after_document_delete", True)
                    clear = request(
                        "POST",
                        "/ops/graph/clear",
                        json={"dataset_id": run, "confirm": "CLEAR_DATASET_GRAPH"},
                    )
                    assert clear["ok"]
                    check("graph_clear", True)
                except Exception as error:
                    cleanup_errors.append(str(error))
                # Even a failed graph assertion must attempt dataset retirement.
                try:
                    retire("/datasets/" + run + "/storage", run + "-delete-dataset")
                    check("dataset_retired", True)
                except Exception as error:
                    cleanup_errors.append(str(error))
            if cleanup_errors:
                summary["status"] = "FAIL"
                summary["cleanup_errors"] = cleanup_errors
            save()
            if cleanup_errors:
                raise RuntimeError("Disposable cleanup needs investigation; see report")
    print(json.dumps({"status": summary["status"], "report": str(REPORT)}), flush=True)


if __name__ == "__main__":
    main()
