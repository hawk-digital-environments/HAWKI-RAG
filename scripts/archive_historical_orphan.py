"""Explicit local, read-only SQL inventory and immutable in-place orphan archive.

Run in the backend one-off container with this repository mounted read-only.
Host-generated producer state evidence is read from stdin. No receipt is minted.
"""

from datetime import datetime, timezone
import hashlib
import json
import os
from pathlib import Path
import sys

import psycopg
from psycopg import sql
from temporalio.client import Client
import asyncio

from hawki_backend.historical_archive import create_archive

SOURCE = "source_ccc6a3b194d8658cffa05ff8a48ffdbb"
DOCUMENT = "adoc_01m4xajrhm1andm0qdfy7ftcbf"
TASK = "task_managed_create_adoc_01m4xajrhm1andm0qdfy7ftcbf"
OPERATION = "delete_a28c5f498d7fc085df3bb5c4a3af4d544b91bdba60f658775e9df74b4b5c8bf6"
PRODUCERS = (
    "hawki_rag_app",
    "hawki_rag_temporal_workflow_worker",
    "hawki_rag_temporal_scraper_worker",
    "hawki_rag_temporal_converter_worker",
    "hawki_rag_indexer_worker",
    "hawki_rag_bridge",
    "crawl4ai-service",
)


def digest_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


async def archive(evidence: dict) -> Path:
    """Preserve this exact known orphan without assigning any active ownership."""
    now = datetime.now(timezone.utc)
    observed = datetime.fromisoformat(evidence["observed_at"])
    if (
        os.environ.get("RAWKI_LOCAL_ORPHAN_ARCHIVE") != "true"
        or os.environ.get("DB_HOST") != "postgres"
        or not 0 <= (now - observed).total_seconds() <= 300
        or any(
            evidence["states"].get(name) != {"state": "exited", "restart": "no"}
            for name in PRODUCERS
        )
    ):
        raise ValueError("Explicit local archive and stopped legacy producers required")
    client = await Client.connect(os.environ["TEMPORAL_ADDRESS"], namespace="default")
    async for _ in client.list_workflows(query='ExecutionStatus = "Running"'):
        raise ValueError("Application execution still active")
    record = {
        "schema_version": 1,
        "category": "unresolved_legacy_deletion",
        "archived_at": now.isoformat(),
        "deletion_completion": "UNVERIFIED",
        "tenant_custody": "UNKNOWN",
        "dataset_custody": "UNKNOWN",
        "active_runtime_owner": None,
        "preservation": "in_place",
        "original_identifiers": {
            "source_id": SOURCE,
            "managed_document_id": DOCUMENT,
            "observed_dataset_label": "gebuhren",
            "operation_id": OPERATION,
        },
        "reserved_identifiers": {
            "source_id": [SOURCE],
            "managed_document_id": [DOCUMENT],
            "task_id": [TASK],
            "job_id": ["ingest_59013c5670dab46d49f7a19a"],
            "dataset_id": ["gebuhren"],
            "operation_id": [OPERATION],
            "workflow_id": ["ingest-source-" + SOURCE, "managed-" + OPERATION],
        },
        "reason": "Historical deletion failed; current tenant/dataset custody and completion unproven. Evidence only; never automatically ingest, adopt or delete.",
        "producer_evidence": evidence,
        "legacy_rows": [],
        "files": [],
        "observations": {
            "writer_recorded_released": True,
            "legacy_writer_gate": "closed",
            "open_application_executions": 0,
        },
        "historical_authority": "Original public.* rows remain authoritative historical evidence; this archive is not an owner, grant or deletion receipt.",
    }
    with psycopg.connect(
        host="postgres",
        dbname=os.environ["POSTGRES_DB"],
        user=os.environ["POSTGRES_USER"],
        password=os.environ["POSTGRES_PASSWORD"],
        options="-c default_transaction_read_only=on",
    ) as connection:
        for table in ("jobs", "failed_jobs"):
            if connection.execute(
                sql.SQL("SELECT count(*) FROM public.{}").format(sql.Identifier(table))
            ).fetchone()[0]:
                raise ValueError("Legacy queue is not empty")
        tables = connection.execute(
            "SELECT schemaname,tablename FROM pg_tables WHERE schemaname IN ('public','hawki_backend') ORDER BY 1,2"
        ).fetchall()
        refs = (SOURCE, DOCUMENT, OPERATION)
        for schema, table in tables:
            if schema == "public" and table in (
                "users",
                "sessions",
                "personal_access_tokens",
                "cache",
                "cache_locks",
                "migrations",
            ):
                continue
            query = sql.SQL("SELECT to_jsonb(t) FROM {}.{} t WHERE ").format(
                sql.Identifier(schema), sql.Identifier(table)
            ) + sql.SQL(" OR ").join([sql.SQL("to_jsonb(t)::text LIKE %s")] * len(refs))
            rows = connection.execute(
                query, tuple("%" + ref + "%" for ref in refs)
            ).fetchall()
            if schema == "hawki_backend" and rows:
                raise ValueError("Orphan already has Python ownership evidence")
            for (row,) in rows:
                record["legacy_rows"].append(
                    {
                        "table": schema + "." + table,
                        "key": {
                            key: row[key]
                            for key in (
                                "id",
                                "operation_id",
                                "document_id",
                                "source_id",
                                "task_id",
                                "event_id",
                            )
                            if key in row
                        },
                        "sha256": hashlib.sha256(
                            json.dumps(row, sort_keys=True).encode()
                        ).hexdigest(),
                    }
                )
                if table == "managed_document_deletions":
                    if row["status"] != "failed" or row["results"] != []:
                        raise ValueError(
                            "Historical operation changed; repeat forensics"
                        )
                if table == "ingestion_sources":
                    metadata = row["metadata"]
                    if metadata.get("managed_writer_gate") != "closed" or any(
                        writer.get("status") != "released"
                        for writer in metadata.get("managed_writers", {}).values()
                    ):
                        raise ValueError("Historical barrier changed")
        if len(record["legacy_rows"]) != 16:
            raise ValueError("Historical evidence set changed; repeat forensics")
    roots = [Path("/shared/sources") / SOURCE, Path("/shared") / TASK]
    for root in roots:
        for path in sorted(root.rglob("*")):
            if path.is_symlink():
                raise ValueError("Forensic path is a symbolic link")
            if path.is_file():
                record["files"].append(
                    {
                        "path": str(path),
                        "size": path.stat().st_size,
                        "sha256": digest_file(path),
                        "role": "original_upload"
                        if root.name == TASK
                        else "source_artifact",
                        "reference": "public.ingestion_sources metadata/upload/storage paths and retained ingest manifest",
                        "moved": False,
                    }
                )
    if len(record["files"]) != 6:
        raise ValueError("Preserved file inventory changed")
    return create_archive(Path("/shared/.historical-orphans"), record)


if __name__ == "__main__":
    try:
        destination = asyncio.run(archive(json.load(sys.stdin)))
        print(
            json.dumps(
                {
                    "archive": str(destination),
                    "custody": "UNKNOWN",
                    "deletion_completion": "UNVERIFIED",
                    "files_preserved": 6,
                }
            )
        )
    except Exception as error:
        print(json.dumps({"archive": "REFUSED", "error_type": type(error).__name__}))
        raise SystemExit(1) from None
