"""Offline, evidence-bound archive of four imported legacy records, never sealing."""

import fcntl
import hashlib
import json
from pathlib import Path
import sys

from hawki_crawler.runtime.store import atomic_json

IDS = (
    "hawk_1780412849803",
    "hawk_1780413098308",
    "hawk_1780413192022",
    "ingest_1645d04ed745e2950c76ce18",
)
PRODUCERS = (
    "hawki_rag_temporal_workflow_worker",
    "hawki_rag_temporal_scraper_worker",
    "hawki_rag_temporal_converter_worker",
    "hawki_rag_indexer_worker",
    "hawki_rag_bridge",
    "crawl4ai-service",
    "rawki-rawki_local_crawler-1",
)


def archive(root: Path, evidence: dict) -> None:
    """Keep identity, artifacts and receipt bytes intact; only write a sidecar."""
    queues = evidence["queues"]
    if (
        queues["namespaces"]["default"]
        or queues["schedules"]
        or queues["sql_queues"]
        or queues["pending_batches"]
        or queues["legacy_crawler_jobs"]
        or any(evidence["states"].get(name) != "exited" for name in PRODUCERS)
    ):
        raise ValueError("Producer isolation or queue drainage is unproven")
    directory = root / ".crawler-runtime"
    with (directory / "owner.lock").open("a+") as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        records = {}
        for job_id in IDS:
            record = json.loads((directory / (job_id + ".json")).read_text())
            artifact = root / job_id / "job_state.json"
            if (
                record["job_id"] != job_id
                or record["status"] != "unknown"
                or record.get("fingerprint") is not None
                or record.get("metadata") != {}
                or any(
                    record.get(flag) is not False
                    for flag in (
                        "sealed",
                        "terminal",
                        "quiescent",
                        "writer_admission_closed",
                    )
                )
                or record.get("sealed_at") is not None
            ):
                raise ValueError("Record is not an imported legacy identity")
            records[job_id] = {
                "classification": "legacy-archive",
                "record_sha256": hashlib.sha256(
                    json.dumps(record, sort_keys=True).encode()
                ).hexdigest(),
                "artifact_state_sha256": hashlib.sha256(
                    artifact.read_bytes()
                ).hexdigest(),
            }
        manifest = {"version": 1, "records": records, "evidence": evidence}
        target = directory / "legacy-archive"
        if target.exists():
            raise ValueError(
                "An archive manifest already exists; preserve it for review"
            )
        atomic_json(target, manifest)


if __name__ == "__main__":
    archive(Path("/shared"), json.load(sys.stdin))
    print("Four legacy records archived; no record, artifact or seal was changed.")
