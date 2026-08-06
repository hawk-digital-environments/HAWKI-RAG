"""Direct FastAPI scenarios for Laravel-to-Temporal bridge commands."""

from __future__ import annotations

import sys
from dataclasses import dataclass
from pathlib import Path
from types import SimpleNamespace
from typing import Any
from unittest.mock import Mock

import pytest
from asgi_client import ASGITestClient as TestClient


PYTHON_RAG_ROOT = Path(__file__).resolve().parents[2]
if str(PYTHON_RAG_ROOT) not in sys.path:
    sys.path.insert(0, str(PYTHON_RAG_ROOT))


@dataclass(frozen=True)
class _TemporalRouteCase:
    """One Temporal bridge route and its observable delegation contract."""

    name: str
    path: str
    payload: dict[str, Any]
    required_field: str
    operation: str
    expected_call: dict[str, Any]
    expected_response: dict[str, Any]


def _workflow_input(*, source_id: str = "source-1") -> dict[str, Any]:
    return {
        "source_id": source_id,
        "source_url": "https://example.test/source",
        "dataset_id": "dataset-a",
        "task_id": "task-a",
        "job_id": "job-a",
        "raw_output_path": f"/shared/sources/{source_id}/raw",
        "markdown_output_path": f"/shared/sources/{source_id}/markdown",
        "storage": {"mode": "shared", "shared_root": "/shared"},
        "ingestion": {
            "provider": "ollama",
            "embedding_model": "bge-m3",
            "collection": None,
        },
        "external_services": {
            "scraper_url": "http://crawl4ai-service",
            "scraper_token": "test-token",
        },
    }


WORKFLOW_INPUT = _workflow_input()


TEMPORAL_ROUTE_CASES = [
    _TemporalRouteCase(
        name="start-workflow",
        path="/temporal/workflows/ingest",
        payload={
            "workflow_id": "ingest-dataset-a",
            "workflow_input": WORKFLOW_INPUT,
        },
        required_field="workflow_id",
        operation="start",
        expected_call={
            "workflow_id": "ingest-dataset-a",
            "workflow_input": WORKFLOW_INPUT,
        },
        expected_response={
            "workflow_id": "ingest-dataset-a",
            "run_id": "run-ingest-dataset-a",
            "schedule_id": None,
        },
    ),
    _TemporalRouteCase(
        name="upsert-schedule",
        path="/temporal/schedules/ingest",
        payload={
            "schedule_id": "schedule-dataset-a",
            "workflow_id": "refresh-dataset-a",
            "cadence": "daily",
            "workflow_input": WORKFLOW_INPUT,
        },
        required_field="cadence",
        operation="upsert_schedule",
        expected_call={
            "schedule_id": "schedule-dataset-a",
            "workflow_id": "refresh-dataset-a",
            "cadence": "daily",
            "workflow_input": WORKFLOW_INPUT,
        },
        expected_response={
            "workflow_id": "refresh-dataset-a",
            "run_id": None,
            "schedule_id": "schedule-dataset-a",
        },
    ),
    _TemporalRouteCase(
        name="delete-schedule",
        path="/temporal/schedules/delete",
        payload={"schedule_id": "schedule-dataset-a"},
        required_field="schedule_id",
        operation="delete_schedule",
        expected_call={"schedule_id": "schedule-dataset-a"},
        expected_response={"ok": True},
    ),
    _TemporalRouteCase(
        name="cancel-workflow",
        path="/temporal/workflows/cancel",
        payload={
            "workflow_id": "ingest-dataset-a",
            "run_id": "run-ingest-dataset-a",
        },
        required_field="workflow_id",
        operation="cancel",
        expected_call={
            "workflow_id": "ingest-dataset-a",
            "run_id": "run-ingest-dataset-a",
        },
        expected_response={"ok": True},
    ),
]


class _FakeTemporalBridgeClient:
    """Record Temporal commands without opening a Temporal connection."""

    def __init__(self, *, failure_operation: str | None = None) -> None:
        self.failure_operation = failure_operation
        self.calls: list[tuple[str, dict[str, Any]]] = []

    def _record(self, operation: str, arguments: dict[str, Any]) -> None:
        self.calls.append((operation, arguments))
        if operation == self.failure_operation:
            raise RuntimeError(f"Temporal {operation} failed.")

    async def start_ingest_workflow(
        self,
        *,
        workflow_id: str,
        workflow_input: dict[str, Any],
    ) -> Any:
        from hawki_bridge.adapters.temporal_client import TemporalExecution

        arguments = {
            "workflow_id": workflow_id,
            "workflow_input": workflow_input,
        }
        self._record("start", arguments)
        return TemporalExecution(
            workflow_id=workflow_id,
            run_id=f"run-{workflow_id}",
        )

    async def upsert_ingest_schedule(
        self,
        *,
        schedule_id: str,
        workflow_id: str,
        cadence: str,
        workflow_input: dict[str, Any],
    ) -> Any:
        from hawki_bridge.adapters.temporal_client import TemporalExecution

        arguments = {
            "schedule_id": schedule_id,
            "workflow_id": workflow_id,
            "cadence": cadence,
            "workflow_input": workflow_input,
        }
        self._record("upsert_schedule", arguments)
        return TemporalExecution(
            workflow_id=workflow_id,
            schedule_id=schedule_id,
        )

    async def delete_schedule(self, *, schedule_id: str) -> None:
        self._record("delete_schedule", {"schedule_id": schedule_id})

    async def cancel_workflow(
        self,
        *,
        workflow_id: str,
        run_id: str | None = None,
    ) -> None:
        self._record(
            "cancel",
            {"workflow_id": workflow_id, "run_id": run_id},
        )


def _build_test_client(tmp_path: Path, client_factory: Any) -> TestClient:
    from hawki_bridge.factory import build_app
    from hawki_bridge.settings import load_settings

    settings = load_settings({})
    app = build_app(
        service=SimpleNamespace(runtime_summary=lambda: {"mode": "test"}),
        qdrant_factory=lambda: object(),
        temporal_client_factory=client_factory,
        logger_name="test.temporal_api_flow",
        settings=settings,
    )
    return TestClient(app)


class TestTemporalApiFlow:
    """Describe validation, delegation, and failure output for each bridge command."""

    @pytest.mark.parametrize(
        "case",
        TEMPORAL_ROUTE_CASES,
        ids=[case.name for case in TEMPORAL_ROUTE_CASES],
    )
    def test_valid_command_is_serialized_and_delegated(
        self,
        case: _TemporalRouteCase,
        tmp_path: Path,
    ) -> None:
        temporal = _FakeTemporalBridgeClient()
        bridge_constructor = Mock(return_value=temporal)

        with _build_test_client(tmp_path, bridge_constructor) as client:
            response = client.post(
                case.path,
                headers={"X-Request-ID": f"{case.name}-success"},
                json=case.payload,
            )

        assert response.status_code == 200
        assert response.json() == case.expected_response
        assert response.headers["X-Request-ID"] == f"{case.name}-success"
        assert temporal.calls == [(case.operation, case.expected_call)]
        bridge_constructor.assert_called_once()

    @pytest.mark.parametrize(
        "case",
        TEMPORAL_ROUTE_CASES,
        ids=[case.name for case in TEMPORAL_ROUTE_CASES],
    )
    def test_missing_required_field_is_rejected_before_client_creation(
        self,
        case: _TemporalRouteCase,
        tmp_path: Path,
    ) -> None:
        invalid_payload = {
            key: value
            for key, value in case.payload.items()
            if key != case.required_field
        }

        bridge_constructor = Mock()
        with _build_test_client(tmp_path, bridge_constructor) as client:
            response = client.post(case.path, json=invalid_payload)

        assert response.status_code == 422
        assert any(
            error["loc"] == ["body", case.required_field] and error["type"] == "missing"
            for error in response.json()["detail"]
        )
        bridge_constructor.assert_not_called()

    @pytest.mark.parametrize(
        "case",
        TEMPORAL_ROUTE_CASES,
        ids=[case.name for case in TEMPORAL_ROUTE_CASES],
    )
    def test_temporal_failure_uses_the_main_bridge_error_envelope(
        self,
        case: _TemporalRouteCase,
        tmp_path: Path,
    ) -> None:
        temporal = _FakeTemporalBridgeClient(failure_operation=case.operation)
        request_id = f"{case.name}-error"
        bridge_constructor = Mock(return_value=temporal)

        with _build_test_client(tmp_path, bridge_constructor) as client:
            response = client.post(
                case.path,
                headers={"X-Request-ID": request_id},
                json=case.payload,
            )

        assert response.status_code == 502
        assert response.json() == {
            "error": {
                "type": "HTTPException",
                "status": 502,
                "message": f"Temporal {case.operation} failed.",
                "path": case.path,
                "request_id": request_id,
            }
        }
        assert temporal.calls == [(case.operation, case.expected_call)]
