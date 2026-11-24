"""Tests for public response sanitization."""

from __future__ import annotations

from hawki_backend.query.response import sanitize_query_response
from hawki_rag_contracts.retrieval.query import QueryResponse


def test_sanitize_removes_nested_physical_identifiers() -> None:
    response = QueryResponse.model_validate(
        {
            "ok": True,
            "count": 1,
            "hits": [
                {
                    "id": "neo4j:a:uses:b",
                    "score": 0.9,
                    "payload": {
                        "content": "A -uses-> B",
                        "dataset_id": "dataset-a",
                        "neo4j_namespace": "secret-namespace",
                        "qdrant_collection": "secret-collection",
                        "nested": {
                            "neo4j_namespace": "deep-secret",
                            "qdrant_collection": "deep-collection",
                            "keep": "value",
                        },
                    },
                }
            ],
            "kg": [{"subject": "A", "relation": "uses", "object": "B"}],
            "answer": "",
            "retrieval": {"dataset_id": "dataset-a"},
        }
    )

    sanitized = sanitize_query_response(response)
    dumped = sanitized.model_dump(mode="json")
    payload = dumped["hits"][0]["payload"]

    assert "neo4j_namespace" not in payload
    assert "qdrant_collection" not in payload
    assert "nested" not in payload
    assert payload["content"] == "A -uses-> B"
    assert payload["dataset_id"] == "dataset-a"
    assert dumped["retrieval"]["dataset_id"] == "dataset-a"


def test_sanitize_preserves_the_logical_response_shape() -> None:
    response = QueryResponse.model_validate(
        {
            "ok": True,
            "count": 0,
            "hits": [],
            "kg": [],
            "answer": "hello",
            "retrieval": {"graph_enabled": False},
        }
    )

    sanitized = sanitize_query_response(response)

    assert sanitized.ok is True
    assert sanitized.count == 0
    assert sanitized.answer == "hello"
    assert sanitized.retrieval == {"graph_enabled": False}


def test_user_metadata_has_separate_public_projection():
    metadata = {
        "course": "design",
        "language": ["en"],
        "year": 2025,
        "open": True,
        "rating": 1.25,
    }
    base = {
        "ingestion_mode": "direct_text",
        "content": "evidence",
        "job_id": "private-job",
        "writer": {"password": "private-db"},
        "idempotency_key": "private-operation",
    }
    response = QueryResponse(
        ok=True,
        count=3,
        hits=[
            {"payload": {**base, "metadata": metadata}, "debug": "private-extra"},
            {"payload": {**base, "metadata": {"auth_claims": "private-claims"}}},
            {
                "payload": {
                    "content": "graph fact",
                    "metadata": {"course": "internal adapter data"},
                }
            },
        ],
    )
    public = sanitize_query_response(response)
    assert public.hits[0].payload == {"content": "evidence"}
    assert public.hits[0].metadata == metadata
    assert public.hits[1].metadata == public.hits[2].metadata == {}
    assert "private-" not in public.model_dump_json()
