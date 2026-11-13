"""Independent local reranker contracts; no model downloads or inference."""

from concurrent.futures import ThreadPoolExecutor
from threading import Barrier, Event, Lock

import pytest
from litellm.types.rerank import RerankResponse

from hawki_bridge.adapters.local_reranker import LocalReranker


class FakeModel:
    def __init__(self, scores: list[float]) -> None:
        self.scores = scores
        self.calls: list[list[tuple[str, str]]] = []

    def predict(self, pairs: list[tuple[str, str]]) -> list[float]:
        self.calls.append(pairs)
        return self.scores


def test_ranking_preserves_indices_pairs_inputs_and_sdk_structure() -> None:
    model = FakeModel([0.2, 0.9, 0.4])
    adapter = LocalReranker(model_factory=lambda _name: model)
    documents = ["first", "best", "middle"]

    response = adapter.rerank("query", documents)

    assert isinstance(response, RerankResponse)
    assert response.model_dump(exclude_none=True) == {
        "results": [
            {"index": 1, "relevance_score": 0.9},
            {"index": 2, "relevance_score": 0.4},
            {"index": 0, "relevance_score": 0.2},
        ]
    }
    assert response.meta is None
    assert response.id is None
    assert model.calls == [[("query", "first"), ("query", "best"), ("query", "middle")]]
    assert documents == ["first", "best", "middle"]


def test_equal_scores_preserve_input_order() -> None:
    adapter = LocalReranker(model_factory=lambda _name: FakeModel([0.5, 0.9, 0.5]))

    response = adapter.rerank("query", ["duplicate", "best", "duplicate"])

    assert response.results is not None
    assert [result["index"] for result in response.results] == [1, 0, 2]


@pytest.mark.parametrize(
    "top_n, expected", [(None, [1, 2, 0]), (1, [1]), (2, [1, 2]), (5, [1, 2, 0])]
)
def test_top_n_limits_results_after_scoring_all_documents(
    top_n: int | None, expected: list[int]
) -> None:
    model = FakeModel([0.2, 0.9, 0.4])
    adapter = LocalReranker(model_factory=lambda _name: model)

    response = adapter.rerank("query", ["first", "best", "middle"], top_n)

    assert response.results is not None
    assert [result["index"] for result in response.results] == expected
    assert len(model.calls) == 1
    assert len(model.calls[0]) == 3


@pytest.mark.parametrize("documents, top_n", [([], None), ([], 2), (["text"], 0)])
def test_empty_results_do_not_load_model(
    documents: list[str], top_n: int | None
) -> None:
    def unexpected_factory(_name: str) -> FakeModel:
        pytest.fail("empty results must not load a model")

    adapter = LocalReranker(model_factory=unexpected_factory)

    assert adapter.rerank("query", documents, top_n).results == []


@pytest.mark.parametrize("documents", [[], ["text"]])
def test_negative_top_n_is_rejected_before_loading(documents: list[str]) -> None:
    def unexpected_factory(_name: str) -> FakeModel:
        pytest.fail("invalid top_n must not load a model")

    with pytest.raises(ValueError, match="top_n"):
        LocalReranker(model_factory=unexpected_factory).rerank("query", documents, -1)


@pytest.mark.parametrize("model_name", [None, "custom/model"])
def test_model_is_loaded_lazily_and_reused(model_name: str | None) -> None:
    names: list[str] = []
    model = FakeModel([0.5])

    def factory(name: str) -> FakeModel:
        names.append(name)
        return model

    adapter = (
        LocalReranker(model_factory=factory)
        if model_name is None
        else LocalReranker(model_name, model_factory=factory)
    )
    assert names == []

    adapter.rerank("first query", ["text"])
    adapter.rerank("second query", ["text"])

    assert names == [model_name or "mixedbread-ai/mxbai-rerank-base-v1"]
    assert len(model.calls) == 2


@pytest.mark.parametrize("scores", [[], [0.1, 0.2], [float("nan")], [float("inf")]])
def test_invalid_model_scores_are_rejected(scores: list[float]) -> None:
    adapter = LocalReranker(model_factory=lambda _name: FakeModel(scores))

    with pytest.raises(ValueError, match="one finite score per document"):
        adapter.rerank("query", ["text"])


def test_concurrent_calls_share_one_model_and_serialize_predictions() -> None:
    workers = 4
    start = Barrier(workers + 1)
    entered_prediction = Event()
    release_prediction = Event()
    state_lock = Lock()
    names: list[str] = []
    active = 0
    max_active = 0
    calls = 0

    class BlockingModel:
        def predict(self, pairs: list[tuple[str, str]]) -> list[float]:
            nonlocal active, max_active, calls
            with state_lock:
                active += 1
                max_active = max(max_active, active)
                calls += 1
            try:
                entered_prediction.set()
                assert release_prediction.wait(timeout=5)
                return [0.5] * len(pairs)
            finally:
                with state_lock:
                    active -= 1

    model = BlockingModel()

    def factory(name: str) -> BlockingModel:
        with state_lock:
            names.append(name)
        return model

    adapter = LocalReranker(model_factory=factory)

    def rerank() -> RerankResponse:
        start.wait(timeout=5)
        return adapter.rerank("query", ["text"])

    with ThreadPoolExecutor(max_workers=workers) as pool:
        futures = [pool.submit(rerank) for _ in range(workers)]
        try:
            start.wait(timeout=5)
            assert entered_prediction.wait(timeout=5)
            assert all(not future.done() for future in futures)
        finally:
            release_prediction.set()
        responses = [future.result(timeout=5) for future in futures]

    assert names == ["mixedbread-ai/mxbai-rerank-base-v1"]
    assert calls == workers
    assert max_active == 1
    assert all(
        response.results == [{"index": 0, "relevance_score": 0.5}]
        for response in responses
    )
