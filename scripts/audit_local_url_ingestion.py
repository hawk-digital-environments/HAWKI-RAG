"""Read-only audit inside a local backend container; read the smoke JSON on stdin."""

from collections import Counter
import json
import os
from pathlib import Path
import sys

import httpx
import sqlalchemy as sa

from hawki_backend.persistence.engine import build_engine
from hawki_backend.persistence.schema import datasets
from hawki_backend.settings import load_database_settings
from hawki_backend.url_ingestion.schema import generations, sources
from hawki_rag_contracts.pipeline.crawler import CrawlerReceipt


def audit() -> dict:
    smoke = json.load(sys.stdin)
    assert smoke["status"] == "PASS"
    dataset_id = smoke["dataset_id"]
    assert dataset_id.startswith("crawler-v2-")
    engine = build_engine(load_database_settings())
    try:
        with engine.connect() as connection, connection.begin():
            connection.execute(sa.text("SET TRANSACTION READ ONLY"))
            dataset = (
                connection.execute(
                    sa.select(datasets).where(
                        datasets.c.tenant_id == "default",
                        datasets.c.dataset_id == dataset_id,
                    )
                )
                .mappings()
                .one()
            )
            rows = list(
                connection.execute(
                    sa.select(generations).where(
                        generations.c.tenant_id == "default",
                        generations.c.dataset_id == dataset_id,
                        generations.c.purpose == "start",
                    )
                ).mappings()
            )
            owned_sources = list(
                connection.execute(
                    sa.select(sources).where(
                        sources.c.tenant_id == "default",
                        sources.c.dataset_id == dataset_id,
                    )
                ).mappings()
            )
        assert dataset["status"] == "deleted"
        assert len(rows) == len(owned_sources) == 3
        assert all(row["status"] == "deleted" for row in owned_sources)
        statuses = Counter()
        headers = (
            {"api-key": os.environ["QDRANT_API_KEY"]}
            if os.environ.get("QDRANT_API_KEY")
            else {}
        )
        with httpx.Client(timeout=10, trust_env=False) as client:
            for row in rows:
                response = client.get(
                    os.environ["EXTERNAL_SCRAPER_URL"] + "/status/" + row["job_id"]
                )
                response.raise_for_status()
                receipt = CrawlerReceipt.model_validate(response.json())
                receipt.verify_owner(row["workflow_input"])
                assert receipt.drained and row["retired"]
                assert not Path("/shared/sources", row["source_id"]).exists()
                assert row["external_job"]["sealed"]
                assert row["external_job"]["quiescent"]
                statuses[receipt.status] += 1
            assert statuses == {"completed": 2, "cancelled": 1}
            collection = dataset["qdrant_collection"]
            result = client.post(
                os.environ["QDRANT_HTTP_URL"].rstrip("/")
                + "/collections/"
                + collection
                + "/points/count",
                headers=headers,
                json={"exact": True},
            )
            if result.status_code == 404:
                points = 0
            else:
                result.raise_for_status()
                points = result.json()["result"]["count"]
            assert points == 0
        return {
            "status": "PASS",
            "read_only": True,
            "dataset_id": dataset_id,
            "dataset_status": dataset["status"],
            "retired_sources": len(owned_sources),
            "sealed_receipts": len(rows),
            "terminal_statuses": dict(statuses),
            "artifacts_remaining": 0,
            "qdrant_points_remaining": points,
        }
    finally:
        engine.dispose()


if __name__ == "__main__":
    try:
        print(json.dumps(audit(), sort_keys=True))
    except Exception as error:
        print(json.dumps({"status": "FAIL", "error_type": type(error).__name__}))
        raise SystemExit(1) from None
