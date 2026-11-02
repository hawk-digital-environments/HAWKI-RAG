"""Request parity between Laravel ``POST /api/query`` and Python ``POST /v1/query``.

For every accepted logical request the internal Python ``QueryRequest`` must
equal the Laravel bridge request captured in the canonical fixture. Only the
two declared normalizations are applied: omitted-vs-default and None-vs-absent
for optional fields. Every other intentional difference is declared on the
case as ``_deviation`` and asserted explicitly.
"""

from __future__ import annotations

from copy import deepcopy
from typing import Any

import pytest
from pydantic import ValidationError

from hawki_backend.api.v1.query import QueryRequestBody, _RESERVED_FILTER_KEYS

from .doubles import build_app, fixture_settings, load_fixture, post_query

FIXTURE = load_fixture()
SETTINGS = fixture_settings(FIXTURE)
ACCEPTED_DEVIATIONS = {
    deviation["id"] for deviation in FIXTURE["_meta"]["accepted_deviations"]
}

PUBLIC_FIELDS = {
    "dataset_id",
    "query",
    "top_k",
    "is_optimized",
    "generate",
    "fast_mode",
    "smart_lookup",
    "preferred_tags",
    "filters",
}

# The documented contract defaults. Filling them is the only omitted-vs-default
# normalization this suite allows.
_CONTRACT_DEFAULTS: dict[str, Any] = {
    "top_k": 5,
    "is_optimized": False,
    "generate": True,
    "fast_mode": True,
    "smart_lookup": False,
    "filters": {},
    "preferred_tags": None,
    "structural_hops": None,
    "reranker": "none",
    "rerank_top_n": 20,
    "mix_mode": True,
    "mix_weight": 0.5,
}


def _accepted_cases() -> list[dict[str, Any]]:
    """Return cases both sides accept without declared deviation of a field."""

    return [
        case
        for case in FIXTURE["request_cases"]
        if case["laravel"]["outcome"] == "request" and "python_expectation" not in case
    ]


def _rejected_cases() -> list[dict[str, Any]]:
    """Return cases where Python is expected to reject the public body."""

    return [case for case in FIXTURE["request_cases"] if "python_expectation" in case]


def _drop_none(value: Any) -> Any:
    """Remove None optional values, matching absent optional fields."""

    if isinstance(value, dict):
        return {
            key: _drop_none(item) for key, item in value.items() if item is not None
        }
    if isinstance(value, list):
        return [_drop_none(item) for item in value]
    return value


def normalize_request(request: dict[str, Any]) -> dict[str, Any]:
    """Apply only the documented normalizations to one internal request."""

    normalized = deepcopy(request)
    for key, default in _CONTRACT_DEFAULTS.items():
        normalized.setdefault(key, default)
    return _drop_none(normalized)


def _get_path(document: dict[str, Any], path: str) -> Any:
    """Return one dotted-path value or fail loudly."""

    current: Any = document
    for part in path.split("."):
        if not isinstance(current, dict) or part not in current:
            raise AssertionError(f"Missing parity path {path!r} in {document!r}")
        current = current[part]
    return current


def _remove_path(document: dict[str, Any], path: str) -> None:
    """Remove one dotted-path value from a document."""

    parts = path.split(".")
    current: Any = document
    for part in parts[:-1]:
        current = current[part]
    del current[parts[-1]]


def _build_internal_request(body: dict[str, Any]) -> dict[str, Any]:
    """Build the internal QueryRequest from public input and trusted scope."""

    request_body = QueryRequestBody.model_validate(body)
    dataset_id = request_body.dataset_id
    scope = SETTINGS.resolve_scope(dataset_id)
    if scope is None:
        raise AssertionError(f"Fixture registry is missing dataset {dataset_id!r}")
    models = SETTINGS.models_for(scope.embedding_provider)
    if models is None:
        raise AssertionError(
            f"Fixture registry is missing models for {scope.embedding_provider!r}"
        )
    return request_body.to_query_request(
        scope=scope,
        models=models,
        defaults=SETTINGS,
    ).model_dump()


@pytest.mark.parametrize(
    "case",
    _accepted_cases(),
    ids=lambda case: case["id"],
)
def test_internal_request_matches_the_laravel_contract(case: dict[str, Any]) -> None:
    """Compare every internal QueryRequest field with the captured contract."""

    actual = _build_internal_request(case["python_body"])
    expected = normalize_request(case["laravel"]["request"])
    actual = normalize_request(actual)

    deviation = case.get("_deviation", {})
    for path, value in deviation.get("python_expected_paths", {}).items():
        assert _get_path(actual, path) == value
    for path in deviation.get("ignored_paths", []):
        _remove_path(actual, path)
        _remove_path(expected, path)

    assert actual == expected


@pytest.mark.parametrize(
    "case",
    _rejected_cases(),
    ids=lambda case: case["id"],
)
def test_documented_rejections_are_enforced(case: dict[str, Any]) -> None:
    """Python rejects the public body exactly as the deviation records."""

    expectation = case["python_expectation"]
    assert expectation["outcome"] == "rejected"
    assert case.get("_deviation"), "A rejection difference must be documented"

    with pytest.raises(ValidationError) as raised:
        QueryRequestBody.model_validate(case["python_body"])

    assert raised.value.error_count() >= 1


def test_rejections_surface_as_http_422() -> None:
    """The public boundary turns every documented validation rejection into 422."""

    application = build_app()

    for case in _rejected_cases():
        response = post_query(application, case["python_body"])

        assert response.status_code == case["python_expectation"]["status"], case["id"]


def test_every_public_field_is_exercised() -> None:
    """The all-fields case must cover the complete public request surface."""

    case = next(
        case for case in FIXTURE["request_cases"] if case["id"] == "all_public_fields"
    )

    assert set(case["python_body"]) == PUBLIC_FIELDS


def test_unknown_top_level_field_is_rejected() -> None:
    """D5: Python is stricter than Laravel and never accepts an unknown field."""

    case = next(
        case
        for case in FIXTURE["request_cases"]
        if case["id"] == "unknown_top_level_field"
    )

    assert case["laravel"]["outcome"] == "request"
    assert case["_deviation"]["id"] == "D5"
    with pytest.raises(ValidationError):
        QueryRequestBody.model_validate(case["python_body"])


def test_embedding_provider_filter_is_rejected() -> None:
    """D6: embedding_provider is authorization-owned and never a filter key."""

    case = next(
        case
        for case in FIXTURE["request_cases"]
        if case["id"] == "embedding_provider_filter"
    )

    assert case["laravel"]["outcome"] == "request"
    assert case["_deviation"]["id"] == "D6"
    with pytest.raises(ValidationError):
        QueryRequestBody.model_validate(case["python_body"])


def test_filter_key_whitespace_is_stripped_and_documented() -> None:
    """D7: Python strips dict keys; the shared builder strips Laravel's key too."""

    case = next(
        case
        for case in FIXTURE["request_cases"]
        if case["id"] == "filter_key_whitespace"
    )

    internal = _build_internal_request(case["python_body"])
    expected = case["_deviation"]["python_expected_paths"]["filters"]

    assert internal["filters"] == expected
    assert case["laravel"]["request"]["filters"] != expected
    assert case["_deviation"]["id"] == "D7"


def test_every_request_deviation_is_registered() -> None:
    """A case may not invent a deviation that the meta contract does not name."""

    declared = {
        case["_deviation"]["id"]
        for case in FIXTURE["request_cases"]
        if "_deviation" in case
    }

    assert declared
    assert declared <= ACCEPTED_DEVIATIONS


@pytest.mark.parametrize("key", sorted(_RESERVED_FILTER_KEYS))
def test_every_reserved_filter_key_is_rejected(key: str) -> None:
    """The public boundary rejects every authorization-owned filter key."""

    response = post_query(
        build_app(),
        {
            "dataset_id": "dataset-alpha",
            "query": "delta epsilon",
            "filters": {key: "x"},
        },
    )

    assert response.status_code == 422, key


def test_fast_mode_graph_scope_difference_is_documented() -> None:
    """The graph derivation difference is declared, not normalized away."""

    case = next(
        case
        for case in FIXTURE["request_cases"]
        if case["id"] == "fast_mode_suppresses_graph_scope"
    )

    internal = _build_internal_request(case["python_body"])
    expected = case["_deviation"]["python_expected_paths"]

    assert (
        internal["authorized_scope"]["graph_enabled"]
        == expected["authorized_scope.graph_enabled"]
    )
    assert case["laravel"]["request"]["authorized_scope"]["graph_enabled"] is False
    assert case["_deviation"]["id"] == "D-GRAPH-DERIVATION"
