"""The retired bridge must never construct a second Temporal controller."""

from unittest.mock import Mock

import pytest

from hawki_bridge.factory import build_app
from hawki_bridge.settings import load_settings


@pytest.mark.parametrize(
    "path",
    [
        "/temporal/workflows/ingest",
        "/temporal/workflows/ingest-text",
        "/temporal/schedules/ingest",
        "/temporal/schedules/delete",
        "/temporal/workflows/cancel",
        "/temporal/workflows/cancel-and-wait",
    ],
)
@pytest.mark.parametrize("nonempty", [False, True])
def test_control_is_retired_before_client_creation(
    path, nonempty, asgi_test_client_class
):
    constructor = Mock()
    app = build_app(
        settings=load_settings({}),
        runtime_summary=lambda: {},
        temporal_client_factory=constructor,
    )
    payload = (
        {
            "workflow_id": "ingest-source-source-a",
            "workflow_input": {"source_id": "source-a"},
        }
        if nonempty
        else {}
    )
    with asgi_test_client_class(app) as client:
        response = client.post(path, json=payload)
    assert response.status_code == 410
    assert "Workflow control moved" in response.text
    constructor.assert_not_called()
