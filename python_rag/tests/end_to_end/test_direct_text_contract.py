"""Verify the opt-in E2E targets current service APIs and always cleans its source."""

import importlib.util
from pathlib import Path

import pytest


@pytest.mark.parametrize("failed", [False, True])
def test_service_api_contract_and_cleanup(monkeypatch, failed):
    spec = importlib.util.spec_from_file_location(
        "current_text_e2e",
        Path(__file__).parent / "integration/test_direct_text_ingestion.py",
    )
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    for name, value in {
        "RAWKI_INTEGRATION_SERVICE_TOKEN": "test-service-jwt",
        "RAWKI_INTEGRATION_TEXT_INGEST_DATASET_ID": "disposable",
        "RAWKI_INTEGRATION_QDRANT_COLLECTION": "physical-disposable",
        "RAWKI_INTEGRATION_BACKEND_URL": "http://backend.test",
    }.items():
        monkeypatch.setenv(name, value)
    calls, state = [], {}
    identity = {
        "task_id": "task",
        "source_id": "source",
        "job_id": "job",
        "workflow_id": "workflow",
    }

    def request(method, url, *, payload=None, headers=None):
        assert headers["Authorization"] == "Bearer test-service-jwt"
        assert url.startswith("http://backend.test/v1/")
        calls.append((method, url, payload, headers))
        if method == "POST":
            state["text"] = payload["text"]
            return 202, identity
        if method == "DELETE":
            state["cleaned"] = True
            return 200, {}
        if "limit=" in url:
            return 200, {}
        return 200, {
            "task": {
                "status": "failed" if failed else "completed",
                "dataset_id": "disposable",
                "jobs": [
                    {
                        "job_id": "job",
                        "source_id": "source",
                        "temporal_workflow_id": "workflow",
                        "index_status": "ready",
                    }
                ],
            }
        }

    def points(collection, source):
        assert (collection, source) == ("physical-disposable", "source")
        if state.get("cleaned"):
            return []
        return [
            {
                "payload": {
                    "content": state["text"].rstrip(),
                    "ingestion_mode": "direct_text",
                }
            }
        ]

    monkeypatch.setattr(module, "_json_request", request)
    monkeypatch.setattr(module, "_source_points", points)
    if failed:
        with pytest.raises(AssertionError):
            module.test_direct_text_reaches_qdrant_and_ready_state(pytest.fail)
    else:
        module.test_direct_text_reaches_qdrant_and_ready_state(pytest.fail)
    assert state["cleaned"]
    posts = [call for call in calls if call[0] == "POST"]
    assert len(posts) == 2
    assert posts[0][2:] == posts[1][2:]
    assert calls[-1][0:2] == ("DELETE", "http://backend.test/v1/text-ingestions/source")
