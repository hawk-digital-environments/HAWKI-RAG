"""Live Qdrant scenarios for collection locking and mandatory dataset filters.

The test creates disposable collections containing deliberately mixed dataset
payloads.  It proves that the application policy overwrites hostile scope
filters and that a scoped client never searches a fallback collection.
"""

from __future__ import annotations

import time
from typing import Any
from urllib.parse import urlsplit
from uuid import uuid4

import pytest


pytestmark = pytest.mark.integration


def _http_settings() -> Any:
    from hawki_vector_store.settings import QdrantHTTPSettings

    return QdrantHTTPSettings(
        log_latency=False,
        search_all=True,
        search_all_per_collection=10,
        fallback_all=True,
        fallback_per_collection=10,
        upsert_timeout=5.0,
        search_timeout=5.0,
        count_timeout=5.0,
        delete_timeout=5.0,
        text_timeout=5.0,
        text_fallback_terms=3,
        text_scroll_hard_cap=100,
        text_scroll_batch=20,
    )


def _client(live_qdrant: Any, collection: str) -> Any:
    from hawki_vector_store.client import QdrantHTTP
    from hawki_vector_store.settings import QdrantSettings

    parsed = urlsplit(live_qdrant.base_url)
    port = parsed.port or (443 if parsed.scheme == "https" else 80)
    settings = QdrantSettings(
        scheme=parsed.scheme,
        host=parsed.hostname or "127.0.0.1",
        port=port,
        collection=collection,
        api_key=live_qdrant.api_key,
        timeout=5.0,
        max_attempts=1,
        retry_attempts_by_operation={},
    )
    return QdrantHTTP(settings=settings, http_settings=_http_settings())


def _create_collection(live_qdrant: Any, collection: str) -> None:
    response = live_qdrant.session.put(
        f"{live_qdrant.base_url}/collections/{collection}",
        json={"vectors": {"size": 3, "distance": "Cosine"}},
        timeout=5.0,
    )
    response.raise_for_status()


def _upsert_and_wait(
    live_qdrant: Any, collection: str, points: list[dict[str, Any]]
) -> None:
    response = live_qdrant.session.put(
        f"{live_qdrant.base_url}/collections/{collection}/points",
        params={"wait": "true"},
        json={"points": points},
        timeout=5.0,
    )
    response.raise_for_status()

    # Older Qdrant releases may acknowledge before the count endpoint reflects
    # the write even with wait=true. Keep the bounded poll local to this test.
    deadline = time.monotonic() + 5.0
    while time.monotonic() < deadline:
        count_response = live_qdrant.session.post(
            f"{live_qdrant.base_url}/collections/{collection}/points/count",
            json={"exact": True},
            timeout=5.0,
        )
        count_response.raise_for_status()
        count = (count_response.json().get("result") or {}).get("count")
        if count == len(points):
            return
        time.sleep(0.05)
    pytest.fail(f"Qdrant did not make {len(points)} test points visible in time")


@pytest.fixture
def qdrant_scope_resources(live_qdrant: Any) -> dict[str, str]:
    """Create unique identifiers and always delete their disposable collections."""

    token = uuid4().hex
    resources = {
        "collection_a": f"rawki_it_scope_a_{token}",
        "collection_b": f"rawki_it_scope_b_{token}",
        "missing_collection": f"rawki_it_scope_missing_{token}",
        "dataset_a": f"rawki-it-dataset-a-{token}",
        "dataset_b": f"rawki-it-dataset-b-{token}",
        "topic": f"rawki-it-topic-{token}",
    }
    try:
        yield resources
    finally:
        for collection in (resources["collection_a"], resources["collection_b"]):
            try:
                live_qdrant.session.delete(
                    f"{live_qdrant.base_url}/collections/{collection}",
                    timeout=5.0,
                )
            except Exception:
                # Cleanup is best-effort only after the endpoint itself becomes
                # unavailable; names are unique and cannot overlap real data.
                pass


class TestLiveQdrantScoping:
    """Prove dataset authorization survives a round trip through real Qdrant."""

    def test_scoped_search_combines_sanitized_user_filter_with_dataset_scope(
        self,
        live_qdrant: Any,
        qdrant_scope_resources: dict[str, str],
    ) -> None:
        from hawki_rag_retrieval.query.scope import build_scoped_query_filters

        resource = qdrant_scope_resources
        _create_collection(live_qdrant, resource["collection_a"])
        _create_collection(live_qdrant, resource["collection_b"])

        _upsert_and_wait(
            live_qdrant,
            resource["collection_a"],
            [
                {
                    "id": str(uuid4()),
                    "vector": [1.0, 0.0, 0.0],
                    "payload": {
                        "dataset_id": resource["dataset_a"],
                        "topic": resource["topic"],
                        "marker": "authorized-in-selected-collection",
                    },
                },
                {
                    "id": str(uuid4()),
                    "vector": [1.0, 0.0, 0.0],
                    "payload": {
                        "dataset_id": resource["dataset_b"],
                        "topic": resource["topic"],
                        "marker": "wrong-dataset-in-selected-collection",
                    },
                },
            ],
        )
        _upsert_and_wait(
            live_qdrant,
            resource["collection_b"],
            [
                {
                    "id": str(uuid4()),
                    "vector": [1.0, 0.0, 0.0],
                    "payload": {
                        "dataset_id": resource["dataset_b"],
                        "topic": resource["topic"],
                        "marker": "wrong-physical-collection",
                    },
                }
            ],
        )

        # The hostile dataset/storage keys are removed and the trusted dataset
        # value is written last by the same policy used by /query.
        filters = build_scoped_query_filters(
            resource["dataset_a"],
            {
                "dataset_id": resource["dataset_b"],
                "qdrantCollection": resource["collection_b"],
                "topic": resource["topic"],
            },
        )
        assert filters == {
            "topic": resource["topic"],
            "dataset_id": resource["dataset_a"],
        }

        qdrant = _client(live_qdrant, resource["collection_b"])
        qdrant.select_scoped_collection(resource["collection_a"])
        hits = qdrant.search([1.0, 0.0, 0.0], top_k=10, filters=filters)

        markers = {(hit.get("payload") or {}).get("marker") for hit in hits}
        assert markers == {"authorized-in-selected-collection"}

    def test_missing_scoped_collection_never_falls_back_globally(
        self,
        live_qdrant: Any,
        qdrant_scope_resources: dict[str, str],
    ) -> None:
        from hawki_vector_store.client import ScopedCollectionNotReadyError

        resource = qdrant_scope_resources
        _create_collection(live_qdrant, resource["collection_b"])
        _upsert_and_wait(
            live_qdrant,
            resource["collection_b"],
            [
                {
                    "id": str(uuid4()),
                    "vector": [1.0, 0.0, 0.0],
                    "payload": {
                        "dataset_id": resource["dataset_b"],
                        "marker": "must-never-be-used-as-fallback",
                    },
                }
            ],
        )

        qdrant = _client(live_qdrant, resource["collection_b"])
        qdrant.select_scoped_collection(resource["missing_collection"])

        with pytest.raises(
            ScopedCollectionNotReadyError,
            match="Authorized dataset storage is not ready",
        ):
            qdrant.search(
                [1.0, 0.0, 0.0],
                top_k=10,
                filters={"dataset_id": resource["dataset_a"]},
            )


@pytest.fixture
def metadata_collection(live_qdrant):
    collection = "rawki_it_metadata_" + uuid4().hex
    _create_collection(live_qdrant, collection)
    client = _client(live_qdrant, collection)
    try:
        # Best semantic score belongs to an excluded point. Some callers also
        # put internal adapter metadata under the same nested storage key.
        points = [
            (
                1,
                "a",
                "direct_text",
                {
                    "year": 2025,
                    "course": "design",
                    "tags": ["ux", "design"],
                    "open": True,
                    "score": 1.25,
                },
                [0.6, 0.8, 0],
            ),
            (
                2,
                "a",
                "direct_text",
                {
                    "year": "2025",
                    "course": "design",
                    "tags": [],
                    "open": False,
                    "score": 2.5,
                },
                [0.8, 0.6, 0],
            ),
            (
                3,
                "a",
                "direct_text",
                {"year": 2024, "course": "cs", "tags": ["cs"], "score": 1},
                [1, 0, 0],
            ),
            (4, "a", "direct_text", {}, [1, 0, 0]),
            (5, "b", "direct_text", {"course": "design", "year": 2025}, [1, 0, 0]),
            (
                6,
                "a",
                "managed_upload",
                {"course": "design", "tags": ["private"]},
                [1, 0, 0],
            ),
            (7, "a", "url", {"course": "design"}, [1, 0, 0]),
            (8, "a", "graph", {}, [1, 0, 0]),
        ]
        _upsert_and_wait(
            live_qdrant,
            collection,
            [
                {
                    "id": point_id,
                    "vector": vector,
                    "payload": {
                        "dataset_id": dataset,
                        "ingestion_mode": mode,
                        "component_type": "relation" if mode == "graph" else "chunk",
                        "metadata": metadata,
                        "content": "lecture material",
                        "source_format": "markdown",
                        "tags": ["lesson"],
                    },
                }
                for point_id, dataset, mode, metadata, vector in points
            ],
        )
        client.select_scoped_collection(collection)
        yield collection, client
    finally:
        client.close()
        url = f"{live_qdrant.base_url}/collections/{collection}"
        live_qdrant.session.delete(url, timeout=5).raise_for_status()
        assert live_qdrant.session.get(url, timeout=5).status_code == 404


def _predicate(field, op, value):
    return {"field": field, "op": op, "value": value}


def _search_metadata(client, expr):
    from hawki_rag_contracts.retrieval.metadata_filter import MetadataFilter
    from hawki_rag_retrieval.adapters.qdrant_reader import QdrantReader
    from hawki_rag_retrieval.query.scope import build_scoped_query_filters

    filters = build_scoped_query_filters(
        "a", {"source_format": "markdown"}, MetadataFilter.model_validate(expr)
    )
    hits = QdrantReader(client).search_candidates(
        vector=[1, 0, 0],
        top_k=64,
        filters=filters,
        query_terms=[],
        keyword_fields=[],
        smart_lookup=False,
        fast_mode=True,
        is_optimized=False,
        preferred_tags=None,
    )
    return {hit["id"] for hit in hits}


def test_metadata_predicates_match_typed_points_in_qdrant(metadata_collection):
    _, client = metadata_collection
    cases = [
        ("year", "eq", 2025, {1}),
        ("year", "eq", "2025", {2}),
        ("course", "eq", "design", {1, 2}),
        ("open", "eq", True, {1}),
        ("open", "eq", 1, set()),
        ("score", "eq", 1.25, {1}),
        ("score", "gt", 1.25, {2}),
        ("score", "gte", 1.25, {1, 2}),
        ("score", "lt", 1.25, {3}),
        ("score", "lte", 1.25, {1, 3}),
        ("year", "neq", 2025, {2, 3}),
        ("tags", "eq", "ux", {1}),
        ("tags", "in", ["ux", "cs"], {1, 3}),
        ("tags", "not_in", ["ux", "cs"], {2}),
        ("tags", "neq", "ux", {2, 3}),
        ("year", "in", [2024, "2025"], {2, 3}),
        ("year", "not_in", [2024, "2025"], {1}),
        ("tags", "exists", True, {1, 2, 3}),
        ("tags", "exists", False, {4, 6, 7}),
        ("course", "eq", "other", set()),
        ("score", "in", [True, 1.25], {1}),
    ]
    for field, op, value, expected in cases:
        expr = _predicate(field, op, value)
        assert _search_metadata(client, expr) == expected, expr
    nested = {
        "and": [
            {"or": [_predicate("year", "eq", 2025), _predicate("course", "eq", "cs")]},
            {"not": _predicate("open", "eq", True)},
        ]
    }
    assert _search_metadata(client, nested) == {3}
    assert _search_metadata(client, {"not": _predicate("tags", "eq", "ux")}) == {
        2,
        3,
        4,
        6,
        7,
    }


def test_metadata_missing_field_semantics_are_unambiguous(metadata_collection):
    """A missing public key fails every comparison, including neq and not_in.

    Point 4 is direct_text, dataset "a", and has empty metadata, so every key
    is missing. Comparison predicates require the key to be present, so point 4
    is excluded from every case below; this pins down neq/not_in rather than
    leaving them ambiguous.
    """

    _, client = metadata_collection
    cases = [
        ("score", "eq", 1.25, {1}),
        ("score", "neq", 1.25, {2, 3}),
        ("score", "in", [1.25, 2.5, 1.0], {1, 2, 3}),
        ("score", "not_in", [9.9], {1, 2, 3}),
        ("score", "gt", 0, {1, 2, 3}),
        ("score", "gte", 1.0, {1, 2, 3}),
        ("score", "lt", 3, {1, 2, 3}),
        ("score", "lte", 1.0, {1, 3}),
        ("score", "exists", True, {1, 2, 3}),
    ]
    for field, op, value, expected in cases:
        assert _search_metadata(client, _predicate(field, op, value)) == expected, (
            field,
            op,
            value,
        )
    # exists false matches the missing key on direct_text payloads, and also
    # payloads that are not direct_text because they cannot hold the key.
    assert _search_metadata(client, _predicate("score", "exists", False)) == {
        4,
        6,
        7,
        8,
    }


def test_numeric_filters_retain_int64_precision(live_qdrant, metadata_collection):
    from operator import eq, gt, ge, lt, le

    collection, client = metadata_collection
    values = [
        -(2**63),
        -(2**63) + 1,
        2**53,
        2**53 + 1,
        2**53 + 2,
        2**63 - 2,
        2**63 - 1,
        float(2**63),
    ]
    points = [
        {
            "id": i,
            "payload": {
                "ingestion_mode": "direct_text",
                "dataset_id": "a",
                "source_format": "markdown",
                "metadata": {"edge": v},
            },
            "vector": [1, 0, 0],
        }
        for i, v in enumerate(values, 101)
    ]
    response = live_qdrant.session.put(
        f"{live_qdrant.base_url}/collections/{collection}/points",
        params={"wait": "true"},
        json={"points": points},
        timeout=5,
    )
    response.raise_for_status()
    for op, compare in [("eq", eq), ("gt", gt), ("gte", ge), ("lt", lt), ("lte", le)]:
        for bound in values:
            expected = {i for i, item in enumerate(values, 101) if compare(item, bound)}
            assert (
                _search_metadata(client, _predicate("edge", op, bound)) == expected
            ), (op, bound)


@pytest.mark.parametrize("smart,exhaustive", [(True, True), (False, False)])
def test_qdrant_filters_every_candidate_before_reranking(
    metadata_collection, monkeypatch, smart, exhaustive
):
    from hawki_rag_contracts.retrieval.query import QueryRequest
    from hawki_rag_retrieval import QueryDependencies, execute_authorized_query
    from hawki_rag_retrieval.adapters.qdrant_reader import QdrantReader

    collection, client = metadata_collection
    monkeypatch.setenv("RAG_EXHAUSTIVE_TEXT", str(exhaustive))
    monkeypatch.setenv("RAG_ITERATIVE_RETRIEVAL", "true")
    monkeypatch.setenv("RAG_CONTEXT_DOCS", "1")
    monkeypatch.setenv("RAG_SEARCH_TOP_K_MULT", "4")
    sends = []
    send = client._gateway.send

    def record(request):
        response = send(request)
        sends.append((request, response))
        return response

    monkeypatch.setattr(client._gateway, "send", record)

    class Provider:
        def embed(self, text):
            return [1, 0, 0]

    reranked = []

    def rerank(*, hits, **kwargs):
        reranked.append([h["id"] for h in hits])
        return [
            {**h, "score": 1 / h["id"]} for h in sorted(hits, key=lambda h: h["id"])
        ]

    request = QueryRequest(
        query="compare lectures",
        provider="ollama",
        chat_model="chat",
        vision_model="vision",
        authorized_scope=dict(
            dataset_id="a",
            qdrant_collection=collection,
            neo4j_namespace="graph",
            graph_enabled=True,
            embedding_provider="ollama",
            embedding_model="embed",
        ),
        top_k=1,
        smart_lookup=smart,
        is_optimized=True,
        fast_mode=False,
        generate=False,
        preferred_tags=["lesson"],
        metadata_filters=_predicate("course", "eq", "design"),
    )
    result = execute_authorized_query(
        request,
        dependencies=QueryDependencies(
            resolve_model_provider=lambda _: Provider(),
            vector_search_factory=lambda: QdrantReader(client),
            graph_search=object(),
            rerank_hits=rerank,
        ),
    )
    assert [hit.id for hit in result.hits] == [1]
    assert (
        result.kg == []
        and result.retrieval["graph_disabled_reason"] == "metadata_filters"
    )
    assert result.retrieval["iterative_pass"] and len(reranked) == 2
    assert all(set(ids) == {1, 2} for ids in reranked)
    assert {req.path.rsplit("/", 1)[1] for req, _ in sends} == {"search", "scroll"}
    for req, response in sends:
        assert req.path.startswith(f"/collections/{collection}/points/")
        predicate = str(req.json_body["filter"])
        assert "metadata.course" in predicate and "dataset_id" in predicate
        response.raise_for_status()
        rows = response.json()["result"]
        if isinstance(rows, dict):
            rows = rows["points"]
        assert {hit["id"] for hit in rows} <= {1, 2}
