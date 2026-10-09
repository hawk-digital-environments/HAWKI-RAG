"""Real PHP serialization/signature consumed by the real FastAPI boundary."""

import json
from pathlib import Path
import shutil
import subprocess
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
        "targets": [{"output_id": 1, "doc_id": "same-id", "source_id": "source-contract",
                     "collection": "historic_collection", "neo4j_namespace": "historic_namespace"}],
        "writers": [],
        "task_queues": {"workflow": "workflow-contract", "indexer": "indexer-contract"},
    }


def php_wire(payload: dict[str, Any]) -> dict[str, Any]:
    root = next(parent for parent in Path(__file__).resolve().parents if (parent / "artisan").exists())
    if shutil.which("php") is None or not (root / "vendor/autoload.php").exists():
        pytest.skip("PHP/vendor dependencies required for the cross-language contract")
    result = subprocess.run(["php", str(root / "tests/Support/managed_deletion_wire.php")],
                            input=json.dumps(payload), text=True, capture_output=True, check=True)
    return json.loads(result.stdout)


def test_real_php_request_is_authenticated_and_delegated(asgi_test_client_class):
    payload = deletion_input()
    wire = php_wire(payload)
    temporal = AsyncMock()
    temporal.start_managed_deletion.return_value = {"workflow_id": "managed-" + payload["operation_id"], "run_id": "contract-run", "status": "pending"}
    app = build_app(runtime_summary=lambda: {}, temporal_client_factory=lambda _settings: temporal,
                    settings=load_settings({"HAWKI_RAG_WORKER_CALLBACK_SECRET": "contract-test-secret"}))
    with asgi_test_client_class(app) as client:
        response = client.post("/temporal/workflows/delete-managed-document", content=wire["body"], headers={**wire["headers"], "Content-Type": "application/json"})
        assert response.status_code == 200, response.text
        assert response.json()["status"] == "pending"
        assert client.request("DELETE", "/documents/same-id").status_code in (404, 405)
    temporal.start_managed_deletion.assert_awaited_once_with(workflow_id="managed-" + payload["operation_id"], workflow_input=payload)


@pytest.mark.parametrize("invalid", ["unsigned", "tampered", "expired", "identity", "extra_scope"])
def test_invalid_request_cannot_dispatch(invalid, asgi_test_client_class):
    payload = deletion_input()
    if invalid == "extra_scope":
        payload["targets"][0]["extra"] = "untrusted"
    wire = php_wire(payload)
    headers = {**wire["headers"], "Content-Type": "application/json"}
    body = wire["body"]
    if invalid == "unsigned":
        headers.pop("X-Hawki-Signature")
    elif invalid == "tampered":
        body = body.replace("historic_collection", "another_collection")
    elif invalid == "expired":
        headers["X-Hawki-Timestamp"] = "1000000000"
    elif invalid == "identity":
        body = body.replace('"workflow_id":"managed-', '"workflow_id":"other-')
    temporal = AsyncMock()
    app = build_app(runtime_summary=lambda: {}, temporal_client_factory=lambda _settings: temporal,
                    settings=load_settings({"HAWKI_RAG_WORKER_CALLBACK_SECRET": "contract-test-secret"}))
    with asgi_test_client_class(app) as client:
        response = client.post("/temporal/workflows/delete-managed-document", content=body, headers=headers)
        assert response.status_code in (401, 422), response.text
    temporal.start_managed_deletion.assert_not_called()
