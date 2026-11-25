"""Archived uncertainty never becomes current ownership or deletion evidence."""

from datetime import datetime, timezone
import asyncio
import hashlib
import hmac
import json
import time
from unittest.mock import Mock

from fastapi import FastAPI, HTTPException
import httpx
import pytest

from hawki_backend.historical_archive import create_archive, require_unreserved
from hawki_backend.datasets.service import DatasetService
from hawki_backend.main import _dataset_graph_default
from hawki_backend.callbacks import router
from hawki_backend.workflow_control.service import WorkflowControl
from datasets.doubles import FakeDatasetStore, FakeCatalog, TENANT


@pytest.fixture
def archived(tmp_path, monkeypatch):
    record = {
        "schema_version": 1,
        "category": "unresolved_legacy_deletion",
        "archived_at": datetime.now(timezone.utc).isoformat(),
        "tenant_custody": "UNKNOWN",
        "dataset_custody": "UNKNOWN",
        "deletion_completion": "UNVERIFIED",
        "active_runtime_owner": None,
        "preservation": "in_place",
        "reserved_identifiers": {
            "dataset_id": ["legacy"],
            "source_id": ["old-source"],
            "task_id": ["old-task"],
            "managed_document_id": ["old-document"],
            "workflow_id": ["old-workflow"],
        },
        "files": [{"path": "/shared/original", "sha256": "a" * 64}],
        "legacy_rows": [{"table": "public.ingestion_sources", "sha256": "b" * 64}],
    }
    path = create_archive(tmp_path, record)
    monkeypatch.setenv("RAG_BACKEND_HISTORICAL_ARCHIVE_ROOT", str(tmp_path))
    return path, record


@pytest.mark.parametrize(
    "kind,value",
    [
        ("dataset_id", "legacy"),
        ("source_id", "old-source"),
        ("task_id", "old-task"),
        ("managed_document_id", "old-document"),
        ("workflow_id", "old-workflow"),
    ],
)
def test_historical_identities_refuse_adoption_without_any_owner_lookup(
    archived, kind, value
):
    with pytest.raises(HTTPException) as caught:
        require_unreserved({kind: value})
    assert caught.value.status_code == 409
    require_unreserved({kind: "fresh"})


def test_evidence_is_immutable_and_tamper_refuses_new_admission(archived):
    path, record = archived
    with pytest.raises(FileExistsError):
        create_archive(path.parent, record)
    path.write_text(json.dumps({**record, "deletion_completion": "completed"}))
    with pytest.raises(HTTPException) as caught:
        require_unreserved({"source_id": "fresh"})
    assert caught.value.status_code == 503


def test_missing_configured_evidence_fails_closed(archived):
    path, _ = archived
    path.unlink()
    with pytest.raises(HTTPException) as caught:
        require_unreserved({"source_id": "fresh"})
    assert caught.value.status_code == 503


def test_archive_refuses_signed_preparation_before_any_dispatch_or_owner_write(
    archived,
):
    repository, port = Mock(), Mock()
    control = WorkflowControl(repository, port)
    with pytest.raises(HTTPException) as caught:
        asyncio.run(
            control.start("source", "old-workflow", {"source_id": "old-source"})
        )
    assert caught.value.status_code == 409
    assert not repository.mock_calls and not port.mock_calls


def test_callback_authentication_precedes_archive_reservation(archived):
    app = FastAPI()
    app.include_router(router)
    app.state.workflow_control_secret = "test-callback-only"
    app.state.workflow_control = None
    raw = json.dumps(
        {
            "schema_version": 1,
            "event_id": "archived-event",
            "action": "acquire",
            "workflow_id": "old-workflow",
            "run_id": "old-run",
            "source_id": "old-source",
            "job_id": "old-job",
            "activity_id": "index",
            "attempt": 1,
            "targets": [],
        }
    ).encode()
    stamp = str(int(time.time()))
    signature = hmac.new(
        b"test-callback-only", stamp.encode() + b"." + raw, hashlib.sha256
    ).hexdigest()

    async def check():
        async with httpx.AsyncClient(
            transport=httpx.ASGITransport(app=app), base_url="http://test"
        ) as client:
            path = "/internal/pipeline/managed-writers"
            denied = await client.post(path, content=raw)
            assert denied.status_code == 422
            authenticated = await client.post(
                path,
                content=raw,
                headers={
                    "X-Hawki-Timestamp": stamp,
                    "X-Hawki-Signature": "v1=" + signature,
                },
            )
            assert authenticated.status_code == 409
            assert authenticated.json()["detail"] == "historical_identity_reserved"

    asyncio.run(check())


def test_reserved_dataset_cannot_be_recreated_under_another_tenant(archived):
    store = FakeDatasetStore()
    service = DatasetService(
        store, FakeCatalog(), embedding_provider="ollama", embedding_model="bge-m3"
    )
    with pytest.raises(HTTPException) as caught:
        service.create("different-tenant", {"dataset_id": "legacy"})
    assert caught.value.status_code == 409
    assert not store.records


def test_graph_default_is_server_owned_and_scope_stays_derived(monkeypatch):
    monkeypatch.delenv("RAG_BACKEND_HISTORICAL_ARCHIVE_ROOT", raising=False)
    store = FakeDatasetStore()
    service = DatasetService(
        store,
        FakeCatalog(),
        embedding_provider="ollama",
        embedding_model="bge-m3",
        graph_enabled=True,
    )
    result = service.create(TENANT, {"dataset_id": "graph-fixture"})
    assert result.record.graph_enabled is True
    assert result.record.neo4j_namespace == result.record.qdrant_collection
    assert result.record.qdrant_collection != "graph-fixture"
    monkeypatch.setenv("RAG_BACKEND_DATASET_GRAPH_ENABLED", "maybe")
    with pytest.raises(ValueError):
        _dataset_graph_default()
