"""Benchmark report timing semantics; no services or ingestion are started."""

import importlib.util
from pathlib import Path

import pytest


@pytest.fixture
def benchmark(monkeypatch):
    scripts = Path(__file__).resolve().parents[2] / "scripts"
    monkeypatch.syspath_prepend(str(scripts))
    spec = importlib.util.spec_from_file_location(
        "ingestion_benchmark", scripts / "benchmark_local_ingestion.py"
    )
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def test_worker_spans_use_timestamps_instead_of_event_delivery_order(benchmark):
    events = [
        {
            "stage": "convert",
            "status": "completed",
            "timestamp": "2026-11-22T12:00:05+00:00",
        },
        {
            "stage": "convert",
            "status": "running",
            "timestamp": "2026-11-22T12:00:01+00:00",
        },
        {
            "stage": "ingest",
            "status": "completed",
            "timestamp": "2026-11-22T12:01:01+00:00",
        },
    ]
    assert benchmark.stage_spans(events) == {
        "scrape": None,
        "convert": 4.0,
        "ingest": None,
    }


@pytest.mark.parametrize(
    "events",
    [
        [],
        [
            {
                "stage": "convert",
                "status": "skipped",
                "timestamp": "2026-11-22T12:00:00+00:00",
            }
        ],
    ],
)
def test_unmeasured_or_skipped_stage_is_not_reported_as_zero(events, benchmark):
    assert all(value is None for value in benchmark.stage_spans(events).values())
