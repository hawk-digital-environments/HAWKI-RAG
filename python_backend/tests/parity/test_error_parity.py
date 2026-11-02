"""Stable error parity between the Laravel query path and the Python boundary.

The fixture records what Laravel answers for each induced failure and what
Python answers for the same condition. The test asserts the Python behavior,
the recorded match classification and that every accepted difference is
declared. Envelope equality is never required for an accepted deviation.
"""

from __future__ import annotations

from typing import Any, Callable

import pytest

from hawki_backend.authorization import InMemoryGrantReader
from hawki_backend.query.settings import QuerySettings
from hawki_rag_retrieval.dependencies import QueryDependencies
from hawki_rag_retrieval.errors import (
    DatasetVectorStoreNotReadyError,
    InvalidQueryError,
)

from .doubles import (
    FakeProvider,
    RecordingGraphSearch,
    RecordingVectorSearch,
    UnavailableGrantReader,
    build_app,
    dependencies,
    identity_rerank,
    load_fixture,
    post_query,
    principal,
)

FIXTURE = load_fixture()
ERROR_CASES = FIXTURE["error_cases"]
ACCEPTED_DEVIATIONS = {
    deviation["id"] for deviation in FIXTURE["_meta"]["accepted_deviations"]
}
CUTOVER_BLOCKERS = {blocker["id"] for blocker in FIXTURE["cutover_blockers"]}

_BASE_BODY = {
    "dataset_id": "dataset-alpha",
    "query": "delta epsilon",
    "top_k": 3,
    "generate": False,
}


def _dependencies_raising(
    *,
    vector_factory: Callable[[], Any] | None = None,
    provider: FakeProvider | None = None,
    provider_resolver: Callable[[str], Any] | None = None,
) -> QueryDependencies:
    """Build dependencies with one failing collaborator."""

    selected_provider = provider or FakeProvider()
    resolver = provider_resolver or (lambda _name: selected_provider)
    return QueryDependencies(
        vector_search_factory=vector_factory or (lambda: RecordingVectorSearch()),
        graph_search=RecordingGraphSearch(),
        resolve_model_provider=resolver,
        rerank_hits=identity_rerank,
    )


def _raise(error: Exception) -> Callable[[], Any]:
    """Return a factory that raises the given error."""

    def factory() -> Any:
        raise error

    return factory


def _unsupported_provider(_name: str) -> Any:
    raise ValueError("unsupported provider")


def _semantic_hits() -> list[dict[str, Any]]:
    case = next(
        case for case in FIXTURE["response_cases"] if case["id"] == "semantic_success"
    )
    return case["dependencies"]["semantic_hits"]


def _run_trigger(
    trigger: str,
    monkeypatch: pytest.MonkeyPatch,
) -> Any:
    """Execute the Python boundary with the failure the trigger names."""

    for name in FIXTURE["configuration_risks"]["shared_rag_env"]:
        monkeypatch.delenv(name, raising=False)

    if trigger == "invalid_top_k":
        return post_query(build_app(), {**_BASE_BODY, "top_k": 0})

    if trigger == "no_token":
        return post_query(build_app(), _BASE_BODY, token=None)

    if trigger == "missing_scope":
        application = build_app(authenticated=principal(scopes=frozenset()))
        return post_query(application, _BASE_BODY)

    if trigger == "missing_grant":
        application = build_app(grant_reader=InMemoryGrantReader())
        return post_query(application, _BASE_BODY)

    if trigger == "grant_store_unavailable":
        application = build_app(grant_reader=UnavailableGrantReader())
        return post_query(application, _BASE_BODY)

    if trigger == "missing_scope_registry":
        application = build_app(
            query_settings=QuerySettings(datasets={}, models={}),
        )
        return post_query(application, _BASE_BODY)

    if trigger == "vector_store_not_ready":
        application = build_app(
            query_dependencies=_dependencies_raising(
                vector_factory=_raise(DatasetVectorStoreNotReadyError())
            )
        )
        return post_query(application, _BASE_BODY)

    if trigger == "invalid_query":
        application = build_app(
            query_dependencies=_dependencies_raising(
                vector_factory=_raise(InvalidQueryError("blocked"))
            )
        )
        return post_query(application, _BASE_BODY)

    if trigger == "unsupported_provider":
        application = build_app(
            query_dependencies=_dependencies_raising(
                provider_resolver=_unsupported_provider
            )
        )
        return post_query(application, _BASE_BODY)

    if trigger == "embedding_failure":
        application = build_app(
            query_dependencies=_dependencies_raising(
                provider=FakeProvider(embed_error=RuntimeError("boom"))
            )
        )
        return post_query(application, _BASE_BODY)

    if trigger == "generation_failure":
        monkeypatch.setenv("RAG_GENERATE_ANSWER", "true")
        application = build_app(
            query_dependencies=dependencies(
                provider=FakeProvider(chat_error=RuntimeError("boom")),
                vector_search=RecordingVectorSearch(semantic_hits=_semantic_hits()),
            )[0]
        )
        return post_query(application, {**_BASE_BODY, "generate": True})

    if trigger == "unexpected_failure":
        application = build_app(
            query_dependencies=_dependencies_raising(
                vector_factory=_raise(RuntimeError("boom"))
            )
        )
        return post_query(application, _BASE_BODY)

    raise AssertionError(f"Unknown error trigger {trigger!r}")


def _code(body: dict[str, Any]) -> str | None:
    """Extract the stable Python error code from the response body."""

    detail = body.get("detail")
    if isinstance(detail, dict):
        code = detail.get("error")
        return code if isinstance(code, str) else None
    if isinstance(detail, str):
        return detail
    return None


def _assert_envelope(kind: str, body: dict[str, Any]) -> None:
    """Assert the declared Python envelope shape."""

    if kind == "fastapi_validation":
        assert isinstance(body.get("detail"), list)
        return
    if kind == "fastapi_detail_string":
        assert isinstance(body.get("detail"), str)
        return
    if kind == "fastapi_detail_object":
        detail = body.get("detail")
        assert isinstance(detail, dict)
        assert set(detail) == {"error", "message"}
        return
    raise AssertionError(f"Unknown Python envelope {kind!r}")


@pytest.mark.parametrize("case", ERROR_CASES, ids=lambda case: case["id"])
def test_python_error_behavior_matches_the_fixture(
    case: dict[str, Any],
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Assert the recorded Python status, code and envelope for each failure."""

    response = _run_trigger(case["trigger"], monkeypatch)
    body = response.json()

    assert response.status_code == case["python"]["status"], body
    assert _code(body) == case["python"]["code"]
    _assert_envelope(case["python"]["envelope"], body)

    for aspect in case["must_match"]:
        assert case["laravel"][aspect] == case["python"][aspect], case["id"]


@pytest.mark.parametrize("case", ERROR_CASES, ids=lambda case: case["id"])
def test_every_error_difference_is_classified(
    case: dict[str, Any],
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """A difference is either matched or explicitly accepted/blocked."""

    classification = case["classification"]

    if classification == "MUST_MATCH":
        assert case["laravel"] == case["python"]
        assert "_deviation" not in case
    elif classification == "ACCEPTED_DEVIATION":
        assert case["_deviation"] in ACCEPTED_DEVIATIONS, case["id"]
    elif classification == "CUTOVER_BLOCKER":
        assert case["blocker"] in CUTOVER_BLOCKERS, case["id"]
        assert "_deviation" not in case
    else:
        raise AssertionError(f"Unknown classification {classification!r}")

    # The trigger must still execute; a classification is not a substitute for
    # a working characterization.
    response = _run_trigger(case["trigger"], monkeypatch)
    assert response.status_code == case["python"]["status"]


@pytest.mark.parametrize("case", ERROR_CASES, ids=lambda case: case["id"])
def test_errors_never_leak_internal_detail(
    case: dict[str, Any],
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """Public errors must not echo storage, provider or exception detail."""

    response = _run_trigger(case["trigger"], monkeypatch)
    serialized = response.text

    for forbidden in (
        "hawki_dataset_alpha",
        "hawki_dataset_graph",
        "Traceback",
        "RuntimeError",
        "ValueError",
        "boom",
    ):
        assert forbidden not in serialized, case["id"]


def test_cutover_blockers_are_documented_in_the_fixture() -> None:
    """The known Epoch 7 blockers stay visible in the canonical fixture."""

    for blocker in ("G1", "G2", "G3", "G12"):
        assert blocker in CUTOVER_BLOCKERS
