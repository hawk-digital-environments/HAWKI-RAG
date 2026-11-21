"""Preserved historical wire bytes remain retired at the compatibility boundary."""

import json
from pathlib import Path
from typing import Any
from unittest.mock import AsyncMock

import pytest

from hawki_bridge.factory import build_app
from hawki_bridge.settings import load_settings


def deletion_input() -> dict[str, Any]:
    return {
        "operation_id": "delete_" + "a" * 64,
        "managed_document_id": "adoc_contract",
        "dataset_id": "dataset-contract",
        "targets": [
            {
                "output_id": 1,
                "doc_id": "same-id",
                "source_id": "source-contract",
                "collection": "historic_collection",
                "neo4j_namespace": "historic_namespace",
            }
        ],
        "writers": [],
        "task_queues": {"workflow": "workflow-contract", "indexer": "indexer-contract"},
    }


def historical_wire(target_count: int) -> dict[str, Any]:
    import hashlib
    import hmac
    import time

    root = next(
        parent
        for parent in Path(__file__).resolve().parents
        if (parent / "python_rag/uv.lock").is_file()
    )
    data = json.loads(
        (
            root
            / "python_rag/packages/contracts/tests/fixtures/historical_control.fixture"
        ).read_text()
    )
    wire = data["cases"]["bridge-deletion-" + str(target_count)]
    stamp = str(int(time.time()))
    signed = (
        f"hawki-workflow-control-v1\nPOST\n/internal/workflow-control/deletion/start\n{stamp}\n".encode()
        + wire["body"].encode()
    )
    signature = (
        "v1=" + hmac.new(b"contract-test-secret", signed, hashlib.sha256).hexdigest()
    )
    return {
        "body": wire["body"],
        "headers": {"X-Hawki-Timestamp": stamp, "X-Hawki-Signature": signature},
    }


@pytest.mark.parametrize("target_count", [1, 1728])
def test_legacy_signed_deletion_is_retired_without_dispatch(
    asgi_test_client_class, target_count
):
    payload = deletion_input()
    payload["targets"] = [
        {**payload["targets"][0], "output_id": index + 1}
        for index in range(target_count)
    ]
    wire = historical_wire(target_count)
    temporal = AsyncMock()
    app = build_app(
        runtime_summary=lambda: {},
        temporal_client_factory=lambda _: temporal,
        settings=load_settings(
            {"HAWKI_RAG_WORKER_CALLBACK_SECRET": "contract-test-secret"}
        ),
    )
    with asgi_test_client_class(app) as client:
        response = client.post(
            "/temporal/workflows/delete-managed-document",
            content=wire["body"],
            headers=wire["headers"],
        )
    assert response.status_code == 410
    temporal.start_managed_deletion.assert_not_awaited()
