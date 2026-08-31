"""Deterministic callback identity contracts shared by worker adapters."""

from hawki_pipeline_callbacks import deterministic_event_id


def test_event_identity_preserves_worker_specific_prefixes() -> None:
    arguments = {
        "workflow_id": "workflow-1",
        "run_id": "run-1",
        "activity_id": "activity-1",
        "attempt": 2,
        "status": "running",
    }

    digest = "e0809017667be60e2b686d3a7d517d8caa1d7b3a0cdc189160f7d10c1cbce18a"
    assert deterministic_event_id(prefix="evt_", **arguments) == f"evt_{digest}"
    assert deterministic_event_id(prefix="scraper.", **arguments) == (
        f"scraper.{digest}"
    )
