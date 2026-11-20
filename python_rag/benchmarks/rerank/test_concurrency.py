"""Concurrency semantics of the benchmark runner (parallel mode)."""

from threading import Barrier, Lock
from time import sleep
from unittest.mock import Mock
import json

import pytest

from benchmarks.rerank.benchmark import ROOT, benchmark
from benchmarks.rerank.test_benchmark import fixture_data, response


@pytest.fixture
def cohere_env(monkeypatch):
    monkeypatch.setenv("COHERE_API_KEY", "private-key")


@pytest.fixture
def barrier_client():
    """Reranker Mock whose first workers meet at a barrier before completing."""

    def make(fail_on_even=False):
        barrier = Barrier(4, timeout=3)
        lock = Lock()
        state = {"received": 0, "peak": 0, "active": 0}

        class Limited(Exception):
            status_code = 429

        def rerank(*args, **kwargs):
            with lock:
                state["received"] += 1
                index = state["received"]
                state["active"] += 1
                state["peak"] = max(state["peak"], state["active"])
            try:
                if index <= 4:
                    barrier.wait()
                sleep(0.005)
                if fail_on_even and index % 2 == 0:
                    raise Limited("private-error-and-key")
                return response()
            finally:
                with lock:
                    state["active"] -= 1

        client = Mock()
        client.rerank.side_effect = rerank
        return client, state

    return make


def test_parallel_requests_overlap_and_failures_respect_the_global_budget(
    barrier_client, cohere_env
):
    spec = json.loads((ROOT / "models.json").read_text())[0]
    client, received = barrier_client(fail_on_even=True)

    report = benchmark(
        [spec, dict(spec, name="second")],
        fixture_data(),
        execute=True,
        allow_remote=True,
        repetitions=10,
        concurrency=4,
        max_remote_calls=7,
        factory=lambda *_: (client, None),
    )
    assert received["received"] == client.rerank.call_count == 7
    assert received["received"] == report["remote_calls_attempted"] == 7
    assert received["peak"] == report["summary"][0]["peak_in_flight"] == 4
    assert received["active"] == 0
    assert len(report["calls"]) == 4
    assert len(report["errors"]) == 3
    assert report["summary"][0]["attempted_calls"] == 7
    assert report["summary"][0]["failed_calls"] == 3
    assert all(error["status_code"] == 429 for error in report["errors"])
    assert sorted(
        row["repetition"] for row in report["calls"] + report["errors"]
    ) == list(range(7))
    assert report["skips"][-1] == {"model": "second", "reason": "remote call limit"}
    assert "private" not in json.dumps(report)


def test_parallel_dry_run_constructs_no_clients():
    def forbidden(*_):
        pytest.fail("dry-run must not construct clients")

    spec = json.loads((ROOT / "models.json").read_text())[0]
    report = benchmark([spec], fixture_data(), concurrency=40, factory=forbidden)
    assert report["concurrency"] == 40
    assert report["remote_calls_attempted"] == 0


def test_invalid_concurrency_and_parallel_local_inference_are_rejected():
    for concurrency in [0, -1]:
        with pytest.raises(ValueError, match="positive"):
            benchmark([], fixture_data(), concurrency=concurrency)
    with pytest.raises(ValueError, match="remote models only"):
        benchmark([{"provider": "local"}], fixture_data(), concurrency=2)
