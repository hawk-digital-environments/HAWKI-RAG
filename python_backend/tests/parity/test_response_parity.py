"""Public response parity for the deterministic query pipeline.

The shared retrieval package is the same on both sides, so a deterministic
double composition must produce the same logical response. The only accepted
response deviations are the physical identifier stripping, wall-clock timings
and the configured generation switch; each is asserted explicitly.
"""

from __future__ import annotations

from copy import deepcopy
from typing import Any

import pytest

from .doubles import (
    FakeProvider,
    RecordingGraphSearch,
    RecordingVectorSearch,
    build_app,
    dependencies,
    load_fixture,
    post_query,
)

FIXTURE = load_fixture()

RESPONSE_KEYS = {"ok", "count", "hits", "kg", "answer", "retrieval"}
PHYSICAL_KEYS = frozenset(
    {"qdrant_collection", "qdrantCollection", "neo4j_namespace", "neo4jNamespace"}
)
PHYSICAL_VALUES = ("hawki_dataset_alpha", "hawki_dataset_graph")


def _strip_physical(value: Any) -> Any:
    """Recursively remove physical identifier keys."""

    if isinstance(value, dict):
        return {
            key: _strip_physical(item)
            for key, item in value.items()
            if key not in PHYSICAL_KEYS
        }
    if isinstance(value, list):
        return [_strip_physical(item) for item in value]
    return value


def _physical_paths(value: Any, prefix: str = "") -> list[str]:
    """Return every path that still carries a physical identifier key."""

    paths: list[str] = []
    if isinstance(value, dict):
        for key, item in value.items():
            path = f"{prefix}.{key}" if prefix else key
            if key in PHYSICAL_KEYS:
                paths.append(path)
            paths.extend(_physical_paths(item, path))
    elif isinstance(value, list):
        for index, item in enumerate(value):
            paths.extend(_physical_paths(item, f"{prefix}[{index}]"))
    return paths


def _round_floats(value: Any, digits: int = 6) -> Any:
    """Round every float so platform float formatting cannot cause drift."""

    if isinstance(value, float):
        return round(value, digits)
    if isinstance(value, dict):
        return {key: _round_floats(item, digits) for key, item in value.items()}
    if isinstance(value, list):
        return [_round_floats(item, digits) for item in value]
    return value


def _comparable(response: dict[str, Any]) -> dict[str, Any]:
    """Return a response comparable across the deterministic harness."""

    comparable = _round_floats(deepcopy(response))
    comparable["retrieval"].pop("timings_ms", None)
    return comparable


def _run_case(
    case: dict[str, Any],
    monkeypatch: pytest.MonkeyPatch,
) -> tuple[dict[str, Any], FakeProvider, RecordingVectorSearch, RecordingGraphSearch]:
    """Execute one fixture response case through the HTTP boundary."""

    for name in FIXTURE["configuration_risks"]["shared_rag_env"]:
        monkeypatch.delenv(name, raising=False)
    for name, value in case["env"].items():
        monkeypatch.setenv(name, value)

    config = case["dependencies"]
    provider = FakeProvider(chat=lambda _prompt: config["chat_answer"])
    vector = RecordingVectorSearch(
        semantic_hits=config["semantic_hits"],
        lexical_hits=config["lexical_hits"],
        secondary_hits=config["secondary_hits"],
    )
    graph = RecordingGraphSearch(
        structural_hits=config["structural_hits"],
        facts=config["facts"],
    )
    query_dependencies, provider, vector, graph = dependencies(
        provider=provider,
        vector_search=vector,
        graph_search=graph,
    )
    application = build_app(query_dependencies=query_dependencies)

    response = post_query(application, case["request"])

    assert response.status_code == 200, response.text
    return response.json(), provider, vector, graph


@pytest.mark.parametrize(
    "case",
    FIXTURE["response_cases"],
    ids=lambda case: case["id"],
)
def test_response_matches_the_deterministic_golden(
    case: dict[str, Any],
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Compare the whole public response except timings and physical identifiers."""

    actual, _, _, _ = _run_case(case, monkeypatch)
    expected = _strip_physical(deepcopy(case["expected"]))

    assert set(actual) == RESPONSE_KEYS
    assert _comparable(actual) == _comparable(expected)


@pytest.mark.parametrize(
    "case",
    FIXTURE["response_cases"],
    ids=lambda case: case["id"],
)
def test_hit_order_ids_and_scores_are_preserved(
    case: dict[str, Any],
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Order, ids and scores must match exactly, scores to six decimals."""

    actual, _, _, _ = _run_case(case, monkeypatch)
    expected = case["expected"]

    assert actual["count"] == expected["count"]
    assert actual["count"] == len(actual["hits"])
    assert [hit["id"] for hit in actual["hits"]] == [
        hit["id"] for hit in expected["hits"]
    ]
    assert [round(hit["score"], 6) for hit in actual["hits"]] == [
        round(hit["score"], 6) for hit in expected["hits"]
    ]
    assert actual["kg"] == expected["kg"]
    assert actual["answer"] == expected["answer"]


def test_graph_enabled_response_strips_physical_identifiers(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """D1: the Laravel golden carries the namespace; Python must remove it."""

    case = next(
        case for case in FIXTURE["response_cases"] if case["id"] == "graph_enabled"
    )
    actual, _, _, _ = _run_case(case, monkeypatch)

    assert _physical_paths(case["expected"]), "The Laravel golden must carry scope"
    assert _physical_paths(actual) == []
    serialized = str(actual)
    for value in PHYSICAL_VALUES:
        assert value not in serialized


def test_all_physical_identifier_variants_are_removed_recursively() -> None:
    """Every documented physical key is stripped from nested mappings and lists."""

    polluted = {
        "id": "point-1",
        "score": 0.9,
        "payload": {
            "content": "delta epsilon",
            "doc_id": "doc-1",
            "qdrant_collection": "hawki_dataset_alpha",
            "nested": {
                "qdrantCollection": "hawki_dataset_alpha",
                "neo4j_namespace": "hawki_dataset_graph",
                "neo4jNamespace": "hawki_dataset_graph",
                "items": [
                    {
                        "qdrant_collection": "hawki_dataset_alpha",
                        "neo4j_namespace": "hawki_dataset_graph",
                    }
                ],
            },
        },
    }
    vector = RecordingVectorSearch(semantic_hits=[polluted])
    query_dependencies, _, _, _ = dependencies(
        provider=FakeProvider(),
        vector_search=vector,
    )
    application = build_app(query_dependencies=query_dependencies)

    response = post_query(
        application,
        {
            "dataset_id": "dataset-alpha",
            "query": "delta epsilon",
            "top_k": 3,
            "generate": False,
        },
    )

    assert response.status_code == 200, response.text
    actual = response.json()
    assert _physical_paths(actual) == []
    assert set(actual) == RESPONSE_KEYS


def test_graph_disabled_fast_mode_never_reads_the_graph(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Fast mode suppresses graph access before any read reaches storage."""

    case = next(
        case
        for case in FIXTURE["response_cases"]
        if case["id"] == "graph_disabled_by_fast_mode"
    )
    actual, _, _, graph = _run_case(case, monkeypatch)

    assert actual["retrieval"]["graph_enabled"] is False
    assert actual["retrieval"]["graph_disabled_reason"] == "fast_mode"
    assert graph.structural_calls == []
    assert graph.fact_calls == []


def test_graph_enabled_reads_use_the_trusted_scope(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Structural and fact reads receive the authorized dataset and namespace."""

    case = next(
        case for case in FIXTURE["response_cases"] if case["id"] == "graph_enabled"
    )
    actual, _, _, graph = _run_case(case, monkeypatch)

    assert actual["retrieval"]["graph_enabled"] is True
    assert graph.structural_calls, "graph-enabled execution must read structure"
    assert graph.fact_calls, "graph-enabled execution must read graph facts"
    for call in (*graph.structural_calls, *graph.fact_calls):
        assert call["dataset_id"] == "dataset-graph"
        assert call["neo4j_namespace"] == "hawki_dataset_graph"


def test_no_hits_is_empty_and_still_reports_metadata(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """An empty result keeps the full stable response shape."""

    case = next(case for case in FIXTURE["response_cases"] if case["id"] == "no_hits")
    actual, _, vector, _ = _run_case(case, monkeypatch)

    assert _comparable(actual) == _comparable(_strip_physical(case["expected"]))
    assert actual["hits"] == []
    assert actual["kg"] == []
    assert actual["answer"] == ""
    assert actual["retrieval"]["iterative_pass"] is True
    assert len(vector.high_recall_calls) == 1


def test_metadata_filters_tags_and_switches_reach_scoped_search(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Caller filters stay additive and trusted scope stays server-derived."""

    for name in FIXTURE["configuration_risks"]["shared_rag_env"]:
        monkeypatch.delenv(name, raising=False)

    case = next(
        case for case in FIXTURE["response_cases"] if case["id"] == "semantic_success"
    )
    vector = RecordingVectorSearch(
        semantic_hits=case["dependencies"]["semantic_hits"],
    )
    query_dependencies, _, _, _ = dependencies(
        provider=FakeProvider(),
        vector_search=vector,
    )
    application = build_app(query_dependencies=query_dependencies)

    response = post_query(
        application,
        {
            "dataset_id": "dataset-alpha",
            "query": "delta epsilon",
            "top_k": 3,
            "generate": False,
            "fast_mode": True,
            "is_optimized": True,
            "smart_lookup": True,
            "preferred_tags": ["handbook"],
            "filters": {"source": "handbook"},
        },
    )

    assert response.status_code == 200, response.text
    assert vector.selected_collections == ["hawki_dataset_alpha"]
    call = vector.search_calls[0]
    assert call["filters"] == {"source": "handbook", "dataset_id": "dataset-alpha"}
    assert call["preferred_tags"] == ["handbook"]
    assert call["is_optimized"] is True
    assert call["smart_lookup"] is True
    assert call["fast_mode"] is True


def test_generation_switch_off_blocks_generation_even_when_requested(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """A request cannot enable generation while the process switch is off."""

    for name in FIXTURE["configuration_risks"]["shared_rag_env"]:
        monkeypatch.delenv(name, raising=False)

    case = next(
        case for case in FIXTURE["response_cases"] if case["id"] == "semantic_success"
    )
    provider = FakeProvider(chat=lambda _prompt: "must not be called")
    query_dependencies, provider, _, _ = dependencies(
        provider=provider,
        vector_search=RecordingVectorSearch(
            semantic_hits=case["dependencies"]["semantic_hits"],
        ),
    )
    application = build_app(query_dependencies=query_dependencies)

    response = post_query(application, {**case["request"], "generate": True})

    assert response.status_code == 200, response.text
    assert provider.chat_calls == 0
    assert response.json()["answer"] == ""


def test_generation_runs_only_when_the_process_switch_is_enabled(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Generation requires both request and shared process configuration."""

    disabled = next(
        case for case in FIXTURE["response_cases"] if case["id"] == "semantic_success"
    )
    _, provider, _, _ = _run_case(disabled, monkeypatch)
    assert provider.chat_calls == 0

    enabled = next(
        case for case in FIXTURE["response_cases"] if case["id"] == "generation_enabled"
    )
    actual, provider, _, _ = _run_case(enabled, monkeypatch)
    assert provider.chat_calls == 1
    assert actual["answer"] == "Alpha beta guidance."
