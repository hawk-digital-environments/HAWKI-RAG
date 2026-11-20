"""Synthetic-payload load tests using fake SDK clients only."""

from threading import Barrier, Lock, current_thread
from time import sleep
from unittest.mock import Mock
from collections import Counter
import json

import pytest
from litellm.types.rerank import RerankResponse

from benchmarks.rerank import benchmark as runner
from benchmarks.rerank import load_test
from benchmarks.rerank.reporting import print_report, write_results


class Limited(Exception):
    status_code = 429


@pytest.fixture(scope="module")
def jina_spec():
    return runner.select_models(
        json.loads((runner.ROOT / "models.json").read_text()), ["jina"]
    )[0]


@pytest.fixture
def fake_response():
    def make(count=24, tokens=100):
        return RerankResponse(
            results=[
                {"index": i, "relevance_score": 1 / (i + 1)} for i in range(count)
            ],
            meta={"tokens": {"input_tokens": tokens}},
        )

    return make


@pytest.fixture
def gateway_env(monkeypatch):
    monkeypatch.setenv("LITELLM_PROXY_API_BASE", "https://ai.ixdlab.hawk.de")
    monkeypatch.setenv("LITELLM_PROXY_API_KEY", "test-key")


@pytest.fixture
def recorded_sleeps(monkeypatch):
    sleeps = []
    monkeypatch.setattr(load_test, "sleep", sleeps.append)
    return sleeps


@pytest.fixture
def synchronized_client(fake_response):
    """Reranker Mock whose workers meet at a barrier before completing."""

    def make(barriers, per_worker, fail_at=()):
        received = 0
        lock = Lock()

        def rerank(query, documents, top_n):
            nonlocal received
            assert len(documents) == top_n == 24
            assert sum(map(len, documents)) == 38452
            assert query.startswith("How does HAWKI-RAG handle ")
            with lock:
                received += 1
                index = received
                phase = 0 if index <= 6 else 1
                worker = (phase, current_thread().name)
                per_worker[worker] += 1
                first = per_worker[worker] == 1
            if first:
                barriers[phase].wait()
            sleep(0.002)
            if index in fail_at:
                raise Limited("private-upstream-error")
            return fake_response()

        client = Mock()
        client.rerank.side_effect = rerank
        return client

    return make


@pytest.fixture
def responding_client(fake_response):
    """Reranker Mock returning plain responses, optionally failing the first call.

    Always use a single callable as side_effect; never a list like
    [exception, callable]: items in a side_effect iterable are returned as
    values, not called, so a callable item silently yields the function object
    instead of its result.
    """

    def make(clear_meta=False, fail_first_with=None):
        calls = {"count": 0}

        def rerank(query, documents, top_n):
            calls["count"] += 1
            if fail_first_with is not None and calls["count"] == 1:
                raise fail_first_with
            result = fake_response()
            if clear_meta:
                result.meta = None
            return result

        client = Mock()
        client.rerank.side_effect = rerank
        return client

    return make


@pytest.fixture
def client_factory():
    """Factory Mock standing in for make_reranker; load_test caches one client."""

    def make(client):
        return Mock(return_value=(client, None))

    return make


@pytest.fixture
def phase_saver(tmp_path):
    """on_phase callback capturing attempts and persisting each phase report."""

    def make():
        attempts = []

        def save(report):
            write_results(report, tmp_path)
            attempts.append(report["remote_calls_attempted"])

        return attempts, save

    return make


def test_synthetic_load_payload_size_and_missing_relevance_judgments():
    data = load_test.synthetic_load_dataset()
    documents = [row["payload"]["content"] for row in data["candidates"]]
    assert len(documents) == 24
    assert sum(map(len, documents)) == 38452
    assert len(data["queries"]) == 6
    assert documents[0].startswith(
        "Dataset deletion document 0. dataset deletion aspect 0.0"
    )
    assert documents[-1].endswith("progress for operators reviewing the run.")
    assert len({row["id"] for row in data["candidates"]}) == 24
    assert all("relevance" not in row for row in data["queries"])


def test_fixed_workers_payload_failures_cooldown_and_client_reuse(
    jina_spec,
    gateway_env,
    recorded_sleeps,
    synchronized_client,
    client_factory,
    phase_saver,
    monkeypatch,
    tmp_path,
):
    monkeypatch.setenv("LITELLM_PROXY_API_KEY", "private-key")
    barriers = {0: Barrier(2, timeout=3), 1: Barrier(4, timeout=3)}
    per_worker = Counter()
    client = synchronized_client(barriers, per_worker, fail_at=(4, 8))
    attempts, save = phase_saver()
    factory = client_factory(client)

    report = load_test.load_sweep(
        [jina_spec],
        levels=[2, 4],
        per=3,
        cooldown=3,
        max_remote_calls=18,
        execute=True,
        allow_remote=True,
        run_benchmark=runner.benchmark,
        factory=factory,
        on_phase=save,
    )
    assert factory.call_count == 1
    assert recorded_sleeps == [3]
    assert (
        client.rerank.call_count
        == report["planned_remote_calls"]
        == report["remote_calls_attempted"]
        == 18
    )
    assert attempts == [6, 18]
    assert len(per_worker) == 6
    assert set(per_worker.values()) == {3}
    assert [row["peak_in_flight"] for row in report["summary"]] == [2, 4]
    assert [row["failed_calls"] for row in report["summary"]] == [1, 1]
    assert [row["reported_input_tokens"] for row in report["summary"]] == [500, 1100]
    assert all(row["ndcg@10"] is None for row in report["summary"])
    assert all(row["status_code"] == 429 for row in report["errors"])
    assert all(
        "all_p95_seconds" in row and "max_seconds" in row for row in report["summary"]
    )
    assert "qrels" not in report
    assert not (tmp_path / "qrels.json").exists()
    assert not (tmp_path / "runs.json").exists()
    assert "private" not in (tmp_path / "results.json").read_text()
    assert json.loads((tmp_path / "results.json").read_text()) == report


def test_request_counts_need_not_be_a_multiple_of_six_and_unknown_usage_stays_unknown(
    jina_spec, gateway_env, responding_client, monkeypatch, capsys
):
    def forbidden_metrics(*_):
        pytest.fail("Unjudged load payload must not calculate quality")

    monkeypatch.setattr(runner, "metrics", forbidden_metrics)
    client = responding_client(clear_meta=True)
    report = load_test.load_sweep(
        [jina_spec],
        levels=[4],
        per=1,
        cooldown=0,
        max_remote_calls=4,
        execute=True,
        allow_remote=True,
        run_benchmark=runner.benchmark,
        factory=lambda *_: (client, None),
    )
    assert client.rerank.call_count == report["remote_calls_attempted"] == 4
    assert {call.args[0] for call in client.rerank.call_args_list} == {
        row["query"] for row in load_test.synthetic_load_dataset()["queries"][:4]
    }
    summary = report["summary"][0]
    assert summary["reported_input_tokens"] is None
    assert summary["input_token_usage_calls"] == 0
    print_report(report, runner.ROOT)
    assert "Input tokens" in capsys.readouterr().out


def test_default_sweep_dry_run_never_constructs_clients_or_sleeps(
    jina_spec, monkeypatch
):
    def forbidden(*_):
        pytest.fail("Dry-run cannot construct clients or sleep")

    monkeypatch.setattr(load_test, "sleep", forbidden)
    report = load_test.load_sweep(
        [jina_spec],
        levels=[24, 32, 48, 64],
        per=1,
        cooldown=3,
        max_remote_calls=168,
        run_benchmark=runner.benchmark,
        factory=forbidden,
    )
    assert report["planned_remote_calls"] == 168
    assert report["remote_calls_attempted"] == 0
    assert len(report["skips"]) == 4


@pytest.mark.parametrize(
    "overrides",
    [
        {"levels": []},
        {"levels": [0]},
        {"levels": [-1]},
        {"per": 0},
        {"cooldown": -1},
        {"cooldown": float("nan")},
        {"cooldown": float("inf")},
        {"max_remote_calls": 1},
        {"models": [{"provider": "local"}]},
    ],
)
def test_invalid_sweep_is_rejected_before_inference(overrides, jina_spec):
    options = dict(
        models=[jina_spec],
        levels=[2],
        per=1,
        cooldown=0,
        max_remote_calls=2,
        run_benchmark=runner.benchmark,
        factory=lambda *_: None,
    )
    options.update(overrides)
    with pytest.raises(ValueError):
        load_test.load_sweep(**options)


def test_cli_load_test_dry_run_uses_synthetic_payload(monkeypatch, tmp_path, capsys):
    monkeypatch.setattr(
        "sys.argv",
        [
            "benchmark.py",
            "--models",
            "jina",
            "--load-test",
            "--levels",
            "24,32,48,64",
            "--per",
            "1",
            "--max-remote-calls",
            "168",
            "--output",
            str(tmp_path),
        ],
    )
    runner.main()
    report = json.loads((tmp_path / "results.json").read_text())
    assert report["payload"]["document_characters"] == 38452
    assert report["planned_remote_calls"] == 168
    assert report["remote_calls_attempted"] == 0
    assert "38452" in capsys.readouterr().out


def test_all_attempt_percentiles_include_failures_but_success_percentiles_do_not(
    jina_spec, gateway_env, responding_client
):
    ticks = iter([0, 4, 10, 12])
    client = responding_client(fail_first_with=TimeoutError("private-timeout"))
    report = runner.benchmark(
        [jina_spec],
        load_test.synthetic_load_dataset(),
        execute=True,
        allow_remote=True,
        requests_per_model=2,
        worker_requests=2,
        max_remote_calls=2,
        factory=lambda *_: (client, None),
        clock=lambda: next(ticks),
    )
    summary = report["summary"][0]
    assert summary["p50_seconds"] == 2
    assert summary["all_p50_seconds"] == 3
    assert summary["all_p95_seconds"] == pytest.approx(3.9)
    assert summary["max_seconds"] == 4


@pytest.mark.parametrize(
    "extra",
    [
        ["--dataset", "unused.json"],
        ["--repetitions", "2"],
        ["--concurrency", "40"],
    ],
)
def test_cli_rejects_mixed_load_and_quality_options(monkeypatch, tmp_path, extra):
    output = tmp_path / "not-written"
    monkeypatch.setattr(
        "sys.argv",
        [
            "benchmark.py",
            "--models",
            "jina",
            "--load-test",
            "--max-remote-calls",
            "168",
            "--output",
            str(output),
            *extra,
        ],
    )
    with pytest.raises(SystemExit) as error:
        runner.main()
    assert error.value.code == 2
    assert not output.exists()
