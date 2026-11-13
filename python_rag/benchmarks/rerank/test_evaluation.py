"""Offline fixtures only; fake scope is not authorization for a live dataset."""

from copy import deepcopy
from types import SimpleNamespace

import pytest
from litellm.types.rerank import RerankResponse

from benchmarks.rerank.collect import FORMAT, capture_hits, collect_one
from benchmarks.rerank.evaluate import evaluate, validate


def snapshot(complete=False):
    return {
        "format": FORMAT,
        "private": True,
        "queries": [
            {
                "id": "q",
                "query": "question",
                "judgment_status": "complete" if complete else "pending",
                "candidates": [
                    {"id": "a", "text": "one", "relevance": 0 if complete else None},
                    {"id": "b", "text": "two", "relevance": 3 if complete else None},
                ],
            }
        ],
    }


class Fake:
    def __init__(self):
        self.calls = []

    def rerank(self, query, documents, top_n):
        self.calls.append((query, documents.copy(), top_n))
        return RerankResponse(
            results=[
                {"index": 1, "relevance_score": 0.9},
                {"index": 0, "relevance_score": 0.1},
            ]
        )


LOCAL = {"name": "local", "provider": "local", "model": "fixture"}
GATEWAY = {
    "name": "jina",
    "provider": "litellm_proxy",
    "model": "litellm_proxy/fixture",
    "key_env": "LITELLM_PROXY_API_KEY",
    "api_base_env": "LITELLM_PROXY_API_BASE",
}


def test_capture_preserves_order_ids_metadata_scores_and_does_not_label():
    hits = [
        {
            "id": 7,
            "score": 0.8,
            "payload": {"doc_id": "d", "chunk_id": "c", "snippet": "hi\x00"},
        },
        {"id": "other", "score": 0.2, "payload": {"content": "text"}},
    ]
    original = deepcopy(hits)
    pool = capture_hits(hits, 20)
    assert [c["original_id"] for c in pool] == [7, "other"]
    assert [c["retrieval_order"] for c in pool] == [0, 1]
    assert [c["retrieval_score"] for c in pool] == [0.8, 0.2]
    assert pool[0]["text"] == "hi"
    assert all(c["relevance"] is None for c in pool)
    pool[0]["metadata"]["doc_id"] = "changed"
    assert hits == original


def test_collector_reuses_pipeline_and_retains_scope(monkeypatch):
    from hawki_bridge.application.query import execution
    from hawki_rag_contracts.retrieval.query import QueryRequest

    request = QueryRequest.model_validate(
        {
            "query": "q",
            "provider": "ollama",
            "chat_model": "fixture",
            "vision_model": "fixture",
            "authorized_scope": {
                "dataset_id": "fixture-only",
                "qdrant_collection": "fixture-collection",
                "embedding_provider": "ollama",
                "embedding_model": "fixture-embedding",
            },
            "filters": {"dataset_id": "hostile"},
        }
    )
    selected = []
    vector = SimpleNamespace(select_scoped_collection=selected.append)
    provider = SimpleNamespace()
    deps = SimpleNamespace(
        resolve_model_provider=lambda _: provider,
        vector_search_factory=lambda: vector,
        graph_search=object(),
    )
    seen = []
    monkeypatch.setattr(
        execution, "prepare_query", lambda *args: SimpleNamespace(text="prepared")
    )

    def retrieve(configured, runtime, prepared, **kwargs):
        assert configured.top_k == 50
        assert runtime.filters["dataset_id"] == "fixture-only"
        assert kwargs["graph_search"] is deps.graph_search
        seen.append("retrieve")
        return [
            {"id": str(i), "score": 0.5, "payload": {"content": "chunk"}}
            for i in range(60)
        ]

    monkeypatch.setattr(execution, "retrieve_query_hits", retrieve)
    monkeypatch.setattr(
        execution,
        "expand_query_hits",
        lambda *a, **kw: pytest.fail("must stop before reranking"),
    )
    result = collect_one(request, {"id": "q", "query": "another"}, deps)
    assert selected == ["fixture-collection"]
    assert seen == ["retrieve"]
    assert len(result["candidates"]) == 50
    assert result["available_candidates"] == 60
    assert result["rerank_query"] == "prepared"
    assert request.query == "q"


@pytest.mark.parametrize("complete", [False, True])
def test_fixed_pool_warmup_and_label_gate(complete):
    adapter = Fake()
    data = snapshot(complete)
    original = deepcopy(data)
    report = evaluate([LOCAL], data, execute=True, factory=lambda *_: (adapter, 0.2))
    assert len(adapter.calls) == 6  # one excluded warmup plus five measurements
    assert all(call == ("question", ["one", "two"], 2) for call in adapter.calls)
    assert len(report["warmups"]) == 1
    assert len(report["calls"]) == 5
    summary = report["summary"][0]
    assert summary["ndcg@5"] == (1 if complete else None)
    assert summary["mrr@10"] == (1 if complete else None)
    assert summary["failure_rate"] == 0
    assert summary["query_coverage"] == 1
    assert report["calls"][0]["document_characters"] == [3, 3]
    assert data == original


def test_incomplete_judgments_never_become_ground_truth():
    data = snapshot()
    data["queries"][0]["candidates"][0]["relevance"] = 3
    report = evaluate([LOCAL], data, execute=True, factory=lambda *_: (Fake(), 0))
    assert all(row["ndcg@10"] is None for row in report["calls"])
    data["queries"][0]["judgment_status"] = "complete"
    with pytest.raises(ValueError):
        validate(data)
    data["queries"][0]["candidates"][1]["relevance"] = 4
    with pytest.raises(ValueError):
        validate(data)


def test_private_pool_never_goes_to_other_providers_and_dry_run_is_offline():
    def forbidden(*_):
        pytest.fail("must not construct model")

    with pytest.raises(ValueError):
        evaluate(
            [dict(LOCAL, provider="cohere")],
            snapshot(),
            execute=True,
            allow_gateway=True,
            factory=forbidden,
        )
    report = evaluate([LOCAL, GATEWAY], snapshot(), factory=forbidden)
    assert report["remote_calls_attempted"] == 0
    assert len(report["skips"]) == 2


def test_gateway_cap_includes_warmup_and_failures(monkeypatch):
    monkeypatch.setenv("LITELLM_PROXY_API_KEY", "fixture-key")
    monkeypatch.setenv("LITELLM_PROXY_API_BASE", "https://ai.ixdlab.hawk.de")

    class FailsOnce(Fake):
        def rerank(self, *args, **kwargs):
            result = super().rerank(*args, **kwargs)
            if len(self.calls) == 2:
                raise RuntimeError("private error message must not be exported")
            return result

    adapter = FailsOnce()
    report = evaluate(
        [GATEWAY],
        snapshot(True),
        execute=True,
        allow_gateway=True,
        max_remote_calls=3,
        factory=lambda *_: (adapter, None),
    )
    assert report["remote_calls_attempted"] == 3
    assert len(report["warmups"]) == 1
    assert len(report["calls"]) == 1
    assert report["summary"][0]["failure_rate"] == 0.5
    assert len(report["skips"]) == 3
    assert "private error" not in str(report)
