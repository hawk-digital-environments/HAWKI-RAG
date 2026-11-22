"""Queue benchmark distributions and Temporal timing interpretation."""

import importlib.util
from pathlib import Path
import sys

import pytest

ROOT = Path(__file__).resolve().parents[2]


def load(name):
    spec = importlib.util.spec_from_file_location(
        name, ROOT / "scripts" / (name + ".py")
    )
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


sys.path.insert(0, str(ROOT / "scripts"))
queue = load("benchmark_local_pdf_queue")
history = load("benchmark_temporal_history")
sys.path.pop(0)


def test_p95_for_ten_files_reports_slowest_sample():
    result = queue.distribution(list(range(1, 11)))
    assert result == {"min": 1, "p50": 5, "p95": 10, "max": 10, "samples": 10}
    assert queue.distribution([]) is None


def test_peak_overlap_does_not_count_touching_intervals_as_parallel():
    rows = [{"started_ns": 0, "finished_ns": 10}, {"started_ns": 10, "finished_ns": 20}]
    assert queue.peak_overlap(rows) == 1
    rows.append({"started_ns": 5, "finished_ns": 15})
    assert queue.peak_overlap(rows) == 2


@pytest.mark.parametrize("attempt", [1, 3])
def test_server_queue_time_is_distinct_from_execution_and_retains_attempt(attempt):
    events = [
        {
            "kind": "scheduled",
            "id": 1,
            "time_ns": 1000000,
            "activity": "index",
            "queue": "indexers",
        },
        {
            "kind": "started",
            "id": 2,
            "time_ns": 5000000,
            "scheduled_id": 1,
            "attempt": attempt,
        },
        {"kind": "completed", "id": 3, "time_ns": 15000000, "scheduled_id": 1},
    ]
    result = history.activity_metrics(events)[0]
    assert result["schedule_to_start_ms"] == 4
    assert result["execution_ms"] == 10
    assert result["attempt"] == attempt
    assert result["queue"] == "indexers"


def test_unstarted_activity_does_not_get_invented_queue_timing():
    assert (
        history.activity_metrics(
            [
                {
                    "kind": "scheduled",
                    "id": 1,
                    "time_ns": 1000000,
                    "activity": "index",
                    "queue": "indexers",
                },
                {"kind": "timed_out", "id": 2, "time_ns": 5000000, "scheduled_id": 1},
            ]
        )
        == []
    )


def test_executor_handoff_can_be_large_even_if_temporal_queue_is_short():
    item = {
        "events": [
            {
                "stage": "ingest",
                "status": "running",
                "timestamp": "1970-01-01T00:00:10+00:00",
            }
        ]
    }
    activity = {"scheduled_ns": 1_000_000_000}
    assert queue.worker_handoff_delay(item, activity) == 9000
    assert queue.worker_handoff_delay({"events": []}, activity) is None


def test_failed_run_report_retains_error_without_inventing_completion_time():
    report = {
        "count": 10,
        "started_at": "2026-11-22T00:00:00+00:00",
        "json_report": "/tmp/test.json",
        "runs": [
            {
                "mode": "graph_off",
                "status": "FAIL",
                "accepted": 10,
                "admission_ms": 720,
                "error": "HTTP 500",
                "cleanup": "PASS",
            }
        ],
    }
    rendered = queue.render(report)
    assert "Failure: HTTP 500" in rendered
    assert "| graph_off | FAIL | 10 | 720 | — | — |" in rendered
    assert "Cleanup: **PASS**" in rendered
