"""Opt-in live integration checks for the Epoch 7 cutover gate.

These tests are never part of the deterministic suite. They are skipped unless
``HAWKI_PARITY_INTEGRATION`` is enabled and the relevant endpoints are
configured. The bridge is contacted over plain HTTP; ``hawki_bridge`` is never
imported.
"""

from __future__ import annotations

import os
from typing import Any

import httpx
import pytest

from hawki_rag_retrieval import build_query_dependencies

from .doubles import (
    build_app,
    fixture_settings,
    load_fixture,
    post_query,
)

FIXTURE = load_fixture()

_INTEGRATION_ENABLED = os.environ.get(
    "HAWKI_PARITY_INTEGRATION", ""
).strip().lower() in {
    "1",
    "true",
    "yes",
}

pytestmark = [
    pytest.mark.integration,
    pytest.mark.skipif(
        not _INTEGRATION_ENABLED,
        reason="set HAWKI_PARITY_INTEGRATION=1 to run live parity integration",
    ),
]

_PHYSICAL_KEYS = frozenset(
    {"qdrant_collection", "qdrantCollection", "neo4j_namespace", "neo4jNamespace"}
)


def _required(name: str) -> str:
    """Return a required integration setting or skip with a clear reason."""

    value = os.environ.get(name, "").strip()
    if not value:
        pytest.skip(f"{name} is not configured")
    return value


def _configured_datasets() -> list[dict[str, Any]]:
    """Return the inventory entries selected for this integration run."""

    selected = os.environ.get("HAWKI_PARITY_DATASETS", "").strip()
    inventory = FIXTURE["dataset_inventory"]
    if not selected:
        return inventory
    wanted = {item.strip() for item in selected.split(",") if item.strip()}
    chosen = [entry for entry in inventory if entry["dataset_id"] in wanted]
    missing = wanted - {entry["dataset_id"] for entry in chosen}
    assert not missing, f"HAWKI_PARITY_DATASETS names unknown inventory ids: {missing}"
    return chosen


def _qdrant_headers() -> dict[str, str]:
    """Return optional Qdrant API headers."""

    api_key = os.environ.get("HAWKI_PARITY_QDRANT_API_KEY", "").strip()
    return {"api-key": api_key} if api_key else {}


def _neo4j_auth() -> tuple[str, str]:
    """Return basic-auth credentials for the Neo4j HTTP endpoint."""

    raw = _required("HAWKI_PARITY_NEO4J_AUTH")
    user, _, password = raw.partition(":")
    assert user and password, "HAWKI_PARITY_NEO4J_AUTH must be user:password"
    return user, password


def _physical_paths(value: Any, prefix: str = "") -> list[str]:
    """Return every remaining physical identifier path."""

    paths: list[str] = []
    if isinstance(value, dict):
        for key, item in value.items():
            path = f"{prefix}.{key}" if prefix else key
            if key in _PHYSICAL_KEYS:
                paths.append(path)
            paths.extend(_physical_paths(item, path))
    elif isinstance(value, list):
        for index, item in enumerate(value):
            paths.extend(_physical_paths(item, f"{prefix}[{index}]"))
    return paths


def _trusted_request(dataset_id: str, *, fast_mode: bool) -> dict[str, Any]:
    """Build the bridge request Laravel would send for the same logical request."""

    settings = fixture_settings()
    scope = settings.resolve_scope(dataset_id)
    assert scope is not None
    models = settings.models_for(scope.embedding_provider)
    assert models is not None
    return {
        "query": os.environ.get("HAWKI_PARITY_QUERY", "handbook policy"),
        "authorized_scope": {
            "dataset_id": dataset_id,
            "qdrant_collection": scope.qdrant_collection,
            "neo4j_namespace": scope.neo4j_namespace,
            "embedding_provider": scope.embedding_provider,
            "embedding_model": scope.embedding_model,
            "graph_enabled": scope.graph_enabled and not fast_mode,
        },
        "top_k": 5,
        "provider": scope.embedding_provider,
        "chat_model": models.chat_model,
        "vision_model": models.vision_model,
        "filters": {},
        "generate": False,
        "is_optimized": False,
        "fast_mode": fast_mode,
        "smart_lookup": False,
        "reranker": "none",
        "rerank_top_n": 20,
        "mix_mode": True,
        "mix_weight": 0.5,
    }


def _bridge_response(request: dict[str, Any]) -> dict[str, Any]:
    """POST one trusted request to the configured bridge over plain HTTP."""

    bridge_url = _required("HAWKI_PARITY_BRIDGE_URL").rstrip("/")
    response = httpx.post(f"{bridge_url}/query", json=request, timeout=120.0)
    assert response.status_code == 200, response.text
    return response.json()


def _python_response(dataset_id: str, *, fast_mode: bool) -> dict[str, Any]:
    """POST the equivalent public request to the real Python boundary."""

    application = build_app(query_dependencies=build_query_dependencies())
    response = post_query(
        application,
        {
            "dataset_id": dataset_id,
            "query": os.environ.get("HAWKI_PARITY_QUERY", "handbook policy"),
            "top_k": 5,
            "generate": False,
            "fast_mode": fast_mode,
        },
    )
    assert response.status_code == 200, response.text
    return response.json()


def _assert_hits_parity(python: dict[str, Any], bridge: dict[str, Any]) -> None:
    """Compare hit identity, order, score, count and kg."""

    assert python["count"] == bridge["count"]
    assert [hit["id"] for hit in python["hits"]] == [
        hit["id"] for hit in bridge["hits"]
    ]
    assert [round(float(hit["score"]), 6) for hit in python["hits"]] == [
        round(float(hit["score"]), 6) for hit in bridge["hits"]
    ]
    assert python["kg"] == bridge["kg"]


def test_inventory_targets_exist_in_live_storage() -> None:
    """A: every configured dataset resolves to matching Qdrant and Neo4j data."""

    qdrant_url = _required("HAWKI_PARITY_QDRANT_URL").rstrip("/")
    headers = _qdrant_headers()

    for entry in _configured_datasets():
        collection = entry["qdrant_collection"]
        with httpx.Client(
            base_url=qdrant_url,
            headers=headers,
            timeout=30.0,
        ) as client:
            collection_response = client.get(f"/collections/{collection}")
            assert collection_response.status_code == 200, collection_response.text

            scroll_response = client.post(
                f"/collections/{collection}/points/scroll",
                json={
                    "filter": {
                        "must": [
                            {
                                "key": "dataset_id",
                                "match": {"value": entry["dataset_id"]},
                            }
                        ]
                    },
                    "limit": 1,
                    "with_payload": True,
                },
            )
            assert scroll_response.status_code == 200, scroll_response.text
            points = scroll_response.json().get("result", {}).get("points", [])
            assert points, f"{entry['dataset_id']} has no points in {collection}"

        if entry["graph_enabled"]:
            neo4j_url = _required("HAWKI_PARITY_NEO4J_URL").rstrip("/")
            auth = _neo4j_auth()
            response = httpx.post(
                f"{neo4j_url}/db/neo4j/tx/commit",
                auth=auth,
                json={
                    "statements": [
                        {
                            "statement": (
                                "MATCH (n) WHERE n.dataset_id = $dataset_id "
                                "RETURN count(n) AS count"
                            ),
                            "parameters": {"dataset_id": entry["dataset_id"]},
                        }
                    ]
                },
                timeout=30.0,
            )
            assert response.status_code == 200, response.text
            rows = response.json()["results"][0]["data"]
            assert rows and rows[0]["row"][0] > 0, (
                f"{entry['dataset_id']} has no graph data"
            )


def test_graph_disabled_parity_against_the_bridge() -> None:
    """B: graph-off hits, order, score, count and kg match the bridge."""

    dataset_id = os.environ.get("HAWKI_PARITY_DATASET", "dataset-alpha")
    python = _python_response(dataset_id, fast_mode=True)
    bridge = _bridge_response(_trusted_request(dataset_id, fast_mode=True))

    _assert_hits_parity(python, bridge)
    assert python["retrieval"]["graph_enabled"] is False
    assert _physical_paths(python) == []


def test_graph_enabled_parity_and_identifier_stripping() -> None:
    """C: graph-on results match and Python never leaks physical identifiers."""

    dataset_id = os.environ.get("HAWKI_PARITY_DATASET", "dataset-graph")
    python = _python_response(dataset_id, fast_mode=False)
    bridge = _bridge_response(_trusted_request(dataset_id, fast_mode=False))

    _assert_hits_parity(python, bridge)
    assert python["retrieval"]["graph_enabled"] is True
    assert _physical_paths(python) == []

    for hit in python["hits"]:
        payload = hit.get("payload") or {}
        if "dataset_id" in payload:
            assert payload["dataset_id"] == dataset_id
