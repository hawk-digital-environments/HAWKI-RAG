"""Real deletion adapters, immutable historical targets, and sink failure evidence."""

import json
import httpx
import pytest

from hawki_backend.managed_documents.cleanup import ManagedCleanup
from hawki_backend.text_ingestion.settings import TextIngestionSettings
from hawki_backend.text_ingestion.vectors import TextVectorDeletion
from hawki_backend.query.settings import DatasetScope, QuerySettings

from .conftest import send
from .test_lifecycle import create, revision, released, ready


@pytest.mark.parametrize(
    "failure", [None, "qdrant_error", "qdrant_partial", "graph_error", "graph_partial"]
)
def test_cleanup_uses_exact_historical_targets_and_requires_both_verified_sinks(
    env, monkeypatch, failure
):
    monkeypatch.setenv("NEO4J_DATABASE", "historical_database")
    payload = create(env, data={"dataset_id": "files", "graph": "true"})
    row = revision(env, payload)
    released(env, row, graph=True)
    ready(env, row)
    calls = []

    def qdrant(request):
        calls.append(request)
        if failure == "qdrant_error":
            return httpx.Response(503)
        if request.url.path.endswith("/delete"):
            return httpx.Response(
                200, json={"status": "ok", "result": {"status": "completed"}}
            )
        return httpx.Response(
            200,
            json={
                "status": "ok",
                "result": {"count": 1 if failure == "qdrant_partial" else 0},
            },
        )

    client = httpx.Client(transport=httpx.MockTransport(qdrant))
    vectors = TextVectorDeletion(
        TextIngestionSettings(qdrant_url="http://qdrant.test"), client=client
    )
    graphs = []

    class Graph:
        def __init__(self, **kwargs):
            graphs.append(kwargs)

        def delete_scoped_document(self, doc_id, request_id):
            if failure == "graph_error":
                raise TimeoutError("uncertain graph commit")
            graphs[-1]["doc_id"] = doc_id
            return {
                "verified": failure != "graph_partial",
                "remaining_contributions": 1 if failure == "graph_partial" else 0,
            }

        def close(self):
            graphs[-1]["closed"] = True

    monkeypatch.setattr("hawki_backend.managed_documents.cleanup.Neo4jGraph", Graph)
    env.service.cleanup = ManagedCleanup(vectors)
    env.service.query_settings = QuerySettings(
        {
            "files": DatasetScope(
                "drifted_vectors", "ollama", "embed", "drifted_graph", True
            )
        },
        env.service.query_settings.models,
    )
    monkeypatch.setenv("NEO4J_DATABASE", "drifted_database")
    result = send(
        env,
        path="/v1/documents/" + payload["document"]["id"],
        method="DELETE",
        key="delete",
    )
    assert result.status_code == (502 if failure else 200), result.text
    doc = env.repository.document("default", payload["document"]["id"])
    assert (doc["status"] == "deleted") is (failure is None)
    assert calls
    for request in calls:
        assert request.url.path.startswith("/collections/historic_vectors/")
        conditions = json.loads(request.content)["filter"]["must"]
        assert {
            condition["key"]: condition["match"]["value"] for condition in conditions
        } == {
            "doc_id": next(
                iter(
                    env.repository.revision(row["revision_id"])["writer_state"][
                        "managed_targets"
                    ]
                )
            ),
            "source_id": row["source_id"],
            "dataset_id": "files",
        }
    if failure not in {"qdrant_error", "qdrant_partial"}:
        assert graphs[0]["neo4j_namespace"] == "historic_graph"
        assert graphs[0]["settings"].database == "historical_database"
        assert graphs[0]["allow_database_fallback"] is False
        assert graphs[0]["closed"] is True
    client.close()


def test_delete_replay_cannot_delete_reused_handle_generation(env):
    payload = create(env)
    row = revision(env, payload)
    released(env, row)
    ready(env, row)
    path = "/v1/documents/" + payload["document"]["id"]
    deleted = send(env, path=path, method="DELETE", key="delete-old")
    assert deleted.status_code == 200
    result = send(env, path=path, method="PUT", data={"force": "true"}, key="replace")
    assert result.status_code == 202
    new = revision(env, result.json())
    released(env, new)
    ready(env, new, "ready-new")
    before = env.service.cleanup.delete.call_count
    replay = send(env, path=path, method="DELETE", key="delete-old")
    assert replay.json() == deleted.json()
    assert (
        env.repository.document("default", payload["document"]["id"])["generation"] == 2
    )
    assert env.service.cleanup.delete.call_count == before
