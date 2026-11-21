"""Read-only storage/workflow verification of smoke_local_graph.py cleanup."""

import asyncio
import json
import os
from pathlib import Path
import sys
import traceback

import httpx
import sqlalchemy as sa
from temporalio.client import Client

from hawki_backend.managed_documents.schema import documents, revisions
from hawki_backend.ops.service import build_graph
from hawki_backend.persistence.engine import build_engine
from hawki_backend.persistence.schema import datasets, dataset_grants
from hawki_backend.settings import load_database_settings
from hawki_backend.text_ingestion.schema import ingest_text_operations


async def audit(smoke: dict) -> dict:
    assert smoke["status"] == "PASS"
    dataset_id = smoke["dataset_id"]
    assert dataset_id.startswith("graph-accept-")
    engine = build_engine(load_database_settings())
    try:
        with engine.connect() as connection, connection.begin():
            connection.execute(sa.text("SET TRANSACTION READ ONLY"))

            def rows(table):
                return list(
                    connection.execute(
                        sa.select(table).where(
                            table.c.tenant_id == "default",
                            table.c.dataset_id == dataset_id,
                        )
                    ).mappings()
                )

            dataset = rows(datasets)[0]
            docs, versions, texts = (
                rows(documents),
                rows(revisions),
                rows(ingest_text_operations),
            )
            remaining_grants = rows(dataset_grants)
        # Dataset retirement retains grant/history rows; the inactive dataset
        # join makes them ineffective. Their presence is not live authority.
        assert dataset["status"] == "deleted"
        assert len(docs) == len(versions) == len(texts) == 1
        assert all(row["status"] == "deleted" for row in docs)
        assert all(row["status"] == "cleaned" for row in versions)
        assert all(row["lifecycle_status"] == "deleted" for row in texts)
        for row in docs + texts:
            assert row["principal_type"] == "service"
        for row in versions + texts:
            assert not Path("/shared/sources", row["source_id"]).exists()
        for row in versions:
            assert not Path(row["upload_path"]).exists()
        for row in texts:
            assert not Path(row["markdown_path"]).exists()
        headers = (
            {"api-key": os.environ["QDRANT_API_KEY"]}
            if os.environ.get("QDRANT_API_KEY")
            else {}
        )
        with httpx.Client(timeout=10, trust_env=False) as client:
            response = client.post(
                os.environ["QDRANT_HTTP_URL"].rstrip("/")
                + "/collections/"
                + dataset["qdrant_collection"]
                + "/points/count",
                headers=headers,
                json={"exact": True},
            )
            if response.status_code == 404:
                points = 0
            else:
                response.raise_for_status()
                points = response.json()["result"]["count"]
            assert points == 0
        graph = build_graph(dataset_id, dataset["neo4j_namespace"])
        try:
            counts = graph.graph_counts()
        finally:
            graph.close()
        assert counts == {"entities": 0, "triplets": 0}
        temporal = await Client.connect(
            os.environ["TEMPORAL_ADDRESS"], namespace="default"
        )
        open_workflows = [
            execution.id
            async for execution in temporal.list_workflows(
                query='ExecutionStatus = "Running"'
            )
        ]
        assert not open_workflows
        return {
            "status": "PASS",
            "read_only": True,
            "dataset_id": dataset_id,
            "dataset_status": dataset["status"],
            "managed_revisions_cleaned": len(versions),
            "text_sources_deleted": len(texts),
            "retained_grant_audit_rows": len(remaining_grants),
            "active_grants_remaining": 0,
            "qdrant_points_remaining": points,
            "neo4j_remaining": counts,
            "source_upload_artifacts_remaining": 0,
            "open_application_workflows": open_workflows,
        }
    finally:
        engine.dispose()


if __name__ == "__main__":
    try:
        print(json.dumps(asyncio.run(audit(json.load(sys.stdin))), sort_keys=True))
    except Exception as error:
        print(
            json.dumps(
                {
                    "status": "FAIL",
                    "error_type": type(error).__name__,
                    "line": traceback.extract_tb(error.__traceback__)[-1].lineno,
                }
            )
        )
        raise SystemExit(1) from None
