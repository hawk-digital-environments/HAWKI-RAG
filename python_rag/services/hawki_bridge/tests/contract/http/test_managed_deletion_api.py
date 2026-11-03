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


def php_wire(payload: dict[str, Any], mode: str = "deletion") -> dict[str, Any]:
    root = next(
        parent
        for parent in Path(__file__).resolve().parents
        if (parent / "artisan").exists()
    )
    if shutil.which("php") is None or not (root / "vendor/autoload.php").exists():
        pytest.skip("PHP/vendor dependencies required for the cross-language contract")
    result = subprocess.run(
        ["php", str(root / "tests/Support/managed_deletion_wire.php"), mode],
        input=json.dumps(payload),
        text=True,
        capture_output=True,
        check=True,
    )
    return json.loads(result.stdout)


@pytest.mark.parametrize("target_count", [1, 1728])
def test_legacy_signed_deletion_is_retired_without_dispatch(
    asgi_test_client_class, target_count
):
    payload = deletion_input()
    payload["targets"] = [
        {**payload["targets"][0], "output_id": index + 1}
        for index in range(target_count)
    ]
    wire = php_wire(payload)
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
