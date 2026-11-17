"""Deterministic benchmark checks; no network or real model loading."""

import csv
import json
import math
import sys
import types

import pytest
from litellm.types.rerank import RerankResponse

from benchmarks.rerank.benchmark import (
    ROOT,
    benchmark,
    make_reranker,
    map_results,
    metrics,
    percentile,
    select_models,
    skip_reason,
    write_results,
    verify_gateway_model,
)


def fixture_data():
    return {
        "candidates": [
            {"id": "a", "payload": {"content": "A"}},
            {"id": "b", "payload": {"content": "B"}},
        ],
        "queries": [{"id": "q", "query": "question", "relevance": {"b": 2}}],
    }


@pytest.fixture
def cross_encoder(monkeypatch):
    """Stub sentence_transformers; local factory tests never touch real weights."""
    stub = types.ModuleType("sentence_transformers")
    monkeypatch.setitem(sys.modules, "sentence_transformers", stub)
    return stub


def response():
    return RerankResponse(
        results=[
            {"index": 1, "relevance_score": 0.9},
            {"index": 0, "relevance_score": 0.1},
        ],
        meta={"tokens": {"input_tokens": 12}},
    )


def test_selection_and_key_permission_checks():
    config = json.loads((ROOT / "models.json").read_text())
    assert {item["name"] for item in config} == {
        "cohere",
        "bge",
        "jina",
        "voyage",
        "local",
    }
    chosen = select_models(config, ["cohere"])
    assert len(chosen) == 1
    spec = chosen[0]
    with pytest.raises(ValueError):
        select_models(config, ["invented"])
    assert skip_reason(spec, False, True, {"COHERE_API_KEY": "secret"}) == "dry-run"
    assert skip_reason(spec, True, False, {}) == "remote calls require --allow-remote"
    assert skip_reason(spec, True, True, {}) == "missing API key"
    assert skip_reason(spec, True, True, {"COHERE_API_KEY": "secret"}) is None
    assert (
        skip_reason(dict(spec, skip="unverified model"), True, True, {})
        == "unverified model"
    )
    assert skip_reason(dict(spec, model=None), True, True, {}) == "unverified model"


def test_metrics_and_percentiles():
    assert metrics(["b", "a"], {"b": 2}).model_dump(by_alias=True) == {
        "ndcg@10": 1,
        "mrr@10": 1,
        "recall@10": 1,
    }
    bad = metrics(["a", "b"], {"b": 2})
    assert bad.ndcg == pytest.approx(1 / math.log2(3))
    assert bad.reciprocal_rank == 0.5
    assert metrics([f"irrelevant-{i}" for i in range(10)] + ["b"], {"b": 2}).recall == 0
    assert metrics([], {}).model_dump(by_alias=True) == {
        "ndcg@10": 0,
        "mrr@10": 0,
        "recall@10": 0,
    }
    assert percentile([1, 3], 0.5) == 2
    assert percentile([1, 3], 0.95) == pytest.approx(2.9)
    assert percentile([], 0.5) is None


def test_mapping_and_validation():
    candidates = fixture_data()["candidates"]
    assert map_results(response(), candidates)[0].model_dump() == {
        "index": 1,
        "id": "b",
        "score": 0.9,
    }
    for results in (
        [{"index": 0, "relevance_score": 0.5}] * 2,
        [{"index": 2, "relevance_score": 0.5}],
        [
            {"index": 0, "relevance_score": float("nan")},
            {"index": 1, "relevance_score": 0.9},
        ],
    ):
        with pytest.raises(ValueError):
            map_results(RerankResponse(results=results), candidates)
    unsorted = RerankResponse(results=list(reversed(response().results)))
    assert map_results(unsorted, candidates) == map_results(response(), candidates)


@pytest.fixture
def mock_reranker_factory():
    def make():
        seen = []

        class MockReranker:
            def rerank(self, query, documents, top_n):
                seen.append((query, documents, top_n))
                return response()

        return MockReranker(), seen

    return make


def test_mock_execution_limits_usage_and_exports(
    monkeypatch, tmp_path, mock_reranker_factory
):
    config = select_models(json.loads((ROOT / "models.json").read_text()), ["cohere"])
    monkeypatch.setenv("COHERE_API_KEY", "secret-must-not-appear")
    client, seen = mock_reranker_factory()

    ticks = iter([1.0, 1.5])
    report = benchmark(
        config,
        fixture_data(),
        execute=True,
        allow_remote=True,
        repetitions=2,
        max_remote_calls=1,
        factory=lambda *_: (client, None),
        clock=lambda: next(ticks),
    )
    assert report["remote_calls_attempted"] == 1
    assert report["planned_remote_calls"] == 2
    assert seen == [("question", ["A", "B"], 2)]
    assert report["calls"][0]["usage"] == {"tokens": {"input_tokens": 12}}
    assert report["calls"][0]["actual_cost_usd"] is None
    assert report["summary"][0]["p50_seconds"] == 0.5
    assert report["skips"][0]["reason"] == "remote call limit"
    write_results(report, tmp_path)
    assert json.loads((tmp_path / "results.json").read_text()) == report
    assert json.loads((tmp_path / "qrels.json").read_text()) == report["qrels"]
    runs = json.loads((tmp_path / "runs.json").read_text())
    assert runs["cohere:repetition:0"]["q"] == {"b": 2, "a": 1}
    with (tmp_path / "summary.csv").open() as stream:
        assert list(csv.DictReader(stream))[0]["model"] == "cohere"
    assert all(
        (tmp_path / f"{name}.csv").exists() for name in ("calls", "errors", "skips")
    )
    assert "secret-must-not-appear" not in (tmp_path / "results.json").read_text()


def test_failures_count_against_budget_and_do_not_expose_messages(monkeypatch):
    spec = json.loads((ROOT / "models.json").read_text())[0]
    monkeypatch.setenv("COHERE_API_KEY", "secret")

    class Broken:
        def rerank(self, *args, **kwargs):
            raise RuntimeError("secret")

    report = benchmark(
        [spec],
        fixture_data(),
        execute=True,
        allow_remote=True,
        factory=lambda *_: (Broken(), None),
    )
    assert report["remote_calls_attempted"] == 1
    assert report["errors"][0]["type"] == "RuntimeError"
    assert "secret" not in json.dumps(report)

    def broken_factory(*_):
        raise ImportError("secret")

    report = benchmark(
        [spec], fixture_data(), execute=True, allow_remote=True, factory=broken_factory
    )
    assert report["errors"][0]["stage"] == "initialization"
    assert report["remote_calls_attempted"] == 0


def test_dry_run_does_not_construct_rerankers():
    def forbidden(*_):
        pytest.fail("dry-run must not construct clients")

    config = json.loads((ROOT / "models.json").read_text())
    report = benchmark(config, fixture_data(), factory=forbidden)
    assert report["planned_remote_calls"] == 3
    assert report["remote_calls_attempted"] == 0
    assert len(report["skips"]) == 5


def test_sdk_is_called_through_existing_client_without_retries(monkeypatch):
    import litellm
    from hawki_reranking.litellm_reranker import LiteLLMReranker

    seen = []

    def fake_sdk(**kwargs):
        seen.append(kwargs)
        return response()

    monkeypatch.setenv("COHERE_API_KEY", "secret")
    monkeypatch.setattr(litellm, "rerank", fake_sdk)
    spec = json.loads((ROOT / "models.json").read_text())[0]
    client, _ = make_reranker(spec, False)
    assert isinstance(client, LiteLLMReranker)
    client.rerank("question", ["A", "B"], 2)
    assert seen[0]["num_retries"] == seen[0]["max_retries"] == 0
    assert seen[0]["api_base"] == "https://api.cohere.com"
    assert seen[0]["return_documents"] is False
    with pytest.raises(ValueError):
        make_reranker(dict(spec, api_base="http://localhost:4000"), False)


@pytest.mark.parametrize("name", ["local", "bge"])
def test_local_uses_existing_client_and_cached_cpu_factory(
    monkeypatch, cross_encoder, name
):
    from hawki_reranking.local_reranker import LocalReranker

    created = []
    predictions = []

    class MockCrossEncoder:
        def __init__(self, name, **kwargs):
            created.append((name, kwargs))

        def predict(self, pairs):
            predictions.append(pairs)
            return [0.1, 0.9]

    cross_encoder.CrossEncoder = MockCrossEncoder
    spec = select_models(json.loads((ROOT / "models.json").read_text()), [name])[0]
    client, initialization = make_reranker(spec, False)
    assert isinstance(client, LocalReranker)
    assert initialization >= 0
    for _ in range(2):
        assert client.rerank("q", ["A", "B"], 2).results == response().results
    assert len(created) == 1
    assert created[0][1] == {"device": "cpu", "local_files_only": True}
    assert predictions == [[("q", "A"), ("q", "B")]] * 2


def test_missing_keys_and_zero_budget_never_construct_remote_clients(monkeypatch):
    def forbidden(*_):
        pytest.fail("skipped models must not construct clients")

    spec = json.loads((ROOT / "models.json").read_text())[0]
    monkeypatch.delenv("COHERE_API_KEY", raising=False)
    report = benchmark(
        [spec], fixture_data(), execute=True, allow_remote=True, factory=forbidden
    )
    assert report["skips"][0]["reason"] == "missing API key"
    monkeypatch.setenv("COHERE_API_KEY", "secret")
    report = benchmark(
        [spec],
        fixture_data(),
        execute=True,
        allow_remote=True,
        max_remote_calls=0,
        factory=forbidden,
    )
    assert report["skips"][0]["reason"] == "remote call limit"


def test_graded_metrics_and_recall_cutoff():
    result = metrics(["support", "direct"], {"support": 1, "direct": 2})
    assert result.ndcg == pytest.approx((1 + 3 / math.log2(3)) / (3 + 1 / math.log2(3)))
    assert result.recall == 1
    assert metrics(["support"], {"support": 1, "direct": 2}).recall == 0.5


@pytest.mark.parametrize(
    "status, alias, error",
    [
        (200, "jina-rerank", None),
        (200, "jlu/jina-rerank", LookupError),
        (200, "jinaai/jina-reranker-v3.5", LookupError),
        (200, "other-model", LookupError),
        (401, "", PermissionError),
        (403, "", PermissionError),
        (404, "", ConnectionError),
    ],
)
def test_gateway_discovery_and_alias_validation(monkeypatch, status, alias, error):
    from benchmarks.rerank import benchmark as module

    monkeypatch.setenv("LITELLM_PROXY_API_BASE", "https://ai.ixdlab.hawk.de/v1")
    monkeypatch.setenv("LITELLM_PROXY_API_KEY", "fake-virtual-key")
    calls = []

    class MockGatewayConnection:
        def __init__(self, host, timeout):
            assert host == "ai.ixdlab.hawk.de"

        def request(self, method, path, headers):
            calls.append((method, path))
            assert headers["Authorization"] == "Bearer fake-virtual-key"

        def getresponse(self):
            self.status = status
            return self

        def read(self):
            return json.dumps({"data": [{"id": alias}]}).encode()

        def close(self):
            pass

    monkeypatch.setattr(module, "HTTPSConnection", MockGatewayConnection)
    spec = select_models(json.loads((ROOT / "models.json").read_text()), ["jina"])[0]
    if error:
        with pytest.raises(error):
            verify_gateway_model(spec)
    else:
        verify_gateway_model(spec)
    assert calls == [("GET", "/v1/models")]


def test_gateway_sdk_prefix_key_base_and_zero_retries(monkeypatch):
    import litellm
    from benchmarks.rerank import benchmark as module

    spec = select_models(json.loads((ROOT / "models.json").read_text()), ["jina"])[0]
    seen = []
    monkeypatch.setenv("LITELLM_PROXY_API_BASE", "https://ai.ixdlab.hawk.de")
    monkeypatch.setenv("LITELLM_PROXY_API_KEY", "fake-virtual-key")
    monkeypatch.setattr(module, "verify_gateway_model", lambda item: None)
    monkeypatch.setattr(
        litellm, "rerank", lambda **kwargs: seen.append(kwargs) or response()
    )
    client, _ = make_reranker(spec, False)
    client.rerank("q", ["A", "B"], 2)
    assert seen[0]["model"] == "litellm_proxy/jina-rerank"
    assert seen[0]["api_base"] == "https://ai.ixdlab.hawk.de"
    assert seen[0]["api_key"] == "fake-virtual-key"
    assert seen[0]["custom_llm_provider"] == "litellm_proxy"
    assert seen[0]["num_retries"] == seen[0]["max_retries"] == 0
    assert (
        skip_reason(spec, True, True, {"LITELLM_PROXY_API_KEY": "fake"})
        == "missing LITELLM_PROXY_API_BASE"
    )
    monkeypatch.setenv("LITELLM_PROXY_API_BASE", "http://other-host")
    with pytest.raises(ValueError):
        make_reranker(spec, False)


def test_environment_file_loads_only_gateway_variables(monkeypatch, tmp_path):
    from benchmarks.rerank.benchmark import load_gateway_environment

    path = tmp_path / "gateway.env"
    path.write_text(
        'LITELLM_PROXY_API_BASE=https://ai.ixdlab.hawk.de\nLITELLM_PROXY_API_KEY="fake-key"\nUNRELATED_TEST_SETTING=ignored\n'
    )
    monkeypatch.delenv("UNRELATED_TEST_SETTING", raising=False)
    monkeypatch.delenv("LITELLM_PROXY_API_BASE", raising=False)
    monkeypatch.delenv("LITELLM_PROXY_API_KEY", raising=False)
    load_gateway_environment(path)
    import os

    assert os.environ["LITELLM_PROXY_API_BASE"] == "https://ai.ixdlab.hawk.de"
    assert os.environ["LITELLM_PROXY_API_KEY"] == "fake-key"
    assert "UNRELATED_TEST_SETTING" not in os.environ


def test_metric_model_serialization_and_zero_relevance():
    from benchmarks.rerank.results import EvaluationMetrics, RankedResult
    from pydantic import ValidationError

    value = metrics(["a"], {"a": 0})
    assert isinstance(value, EvaluationMetrics)
    assert value.model_dump(by_alias=True) == {
        "ndcg@10": 0,
        "mrr@10": 0,
        "recall@10": 0,
    }
    assert (
        EvaluationMetrics.model_validate_json(value.model_dump_json(by_alias=True))
        == value
    )
    ranked = map_results(response(), [{"id": "a"}, {"id": "b"}])
    assert all(isinstance(result, RankedResult) for result in ranked)
    assert ranked[0].model_dump() == {"index": 1, "id": "b", "score": 0.9}
    with pytest.raises(ValidationError):
        EvaluationMetrics(ndcg=float("nan"), reciprocal_rank=0, recall=0)


@pytest.mark.parametrize("execute", [False, True])
@pytest.mark.parametrize("provider", ["local", "cohere"])
def test_duplicate_query_ids_rejected_before_client_construction(
    execute, provider, monkeypatch
):
    data = fixture_data()
    data["queries"].append(
        {"id": "q", "query": "different question", "relevance": {"a": 2, "b": 0}}
    )
    spec = select_models(json.loads((ROOT / "models.json").read_text()), [provider])[0]
    monkeypatch.setenv("COHERE_API_KEY", "test-placeholder")

    def forbidden(*_):
        pytest.fail(
            "duplicate query IDs must be rejected before constructing any client"
        )

    with pytest.raises(ValueError, match="Duplicate query IDs: 'q'.*unique ID"):
        benchmark([spec], data, execute=execute, allow_remote=True, factory=forbidden)


@pytest.mark.parametrize("execute", [False, True])
def test_duplicate_query_ids_cli_does_not_export(
    execute, monkeypatch, tmp_path, capsys
):
    import sys
    from benchmarks.rerank import benchmark as module

    data = fixture_data()
    data["queries"].append(
        {"id": "q", "query": "different question", "relevance": {"a": 2, "b": 0}}
    )
    dataset = tmp_path / "dataset.json"
    dataset.write_text(json.dumps(data), encoding="utf-8")
    output = tmp_path / "results"
    real_benchmark = module.benchmark

    def forbidden(*_):
        pytest.fail("validation failure must not construct clients or export results")

    def run_benchmark(*args, **kwargs):
        return real_benchmark(*args, **kwargs, factory=forbidden)

    monkeypatch.setattr(module, "benchmark", run_benchmark)
    monkeypatch.setattr(module, "write_results", forbidden)
    monkeypatch.setattr(
        sys,
        "argv",
        [
            "benchmark",
            "--dataset",
            str(dataset),
            "--output",
            str(output),
            "--models",
            "local",
        ]
        + (["--execute"] if execute else []),
    )
    with pytest.raises(SystemExit) as error:
        module.main()
    assert error.value.code == 2
    assert "Duplicate query IDs: 'q'" in capsys.readouterr().err
    assert not output.exists()


def test_unique_query_ids_preserve_separate_qrels_and_runs(
    mock_reranker_factory, tmp_path
):
    data = fixture_data()
    data["queries"].append(
        {"id": "q2", "query": "different question", "relevance": {"a": 2, "b": 0}}
    )
    spec = select_models(json.loads((ROOT / "models.json").read_text()), ["local"])[0]
    client, calls = mock_reranker_factory()
    report = benchmark([spec], data, execute=True, factory=lambda *_: (client, 0))
    assert len(calls) == 2
    assert [row["query"] for row in report["calls"]] == ["q", "q2"]
    write_results(report, tmp_path)
    assert json.loads((tmp_path / "qrels.json").read_text()) == {
        "q": {"b": 2},
        "q2": {"a": 2, "b": 0},
    }
    assert json.loads((tmp_path / "runs.json").read_text()) == {
        "local:repetition:0": {"q": {"b": 2, "a": 1}, "q2": {"b": 2, "a": 1}}
    }


@pytest.mark.parametrize("explicit_provider", [None, "litellm_proxy"])
def test_installed_sdk_resolves_gateway_routing_alias(explicit_provider):
    from litellm.litellm_core_utils.get_llm_provider_logic import get_llm_provider

    model, provider, key, base = get_llm_provider(
        model="litellm_proxy/jina-rerank",
        custom_llm_provider=explicit_provider,
        api_base="https://ai.ixdlab.hawk.de",
        api_key="test-placeholder",
    )
    assert model == "jina-rerank"
    assert provider == "litellm_proxy"
    assert key == "test-placeholder"
    assert base == "https://ai.ixdlab.hawk.de"
