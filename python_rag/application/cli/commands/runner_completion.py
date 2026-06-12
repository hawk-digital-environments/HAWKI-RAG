"""Completion reporting and exit-code helpers for crawl-ingest runner."""

from __future__ import annotations

from pathlib import Path
from typing import Any

EXIT_RUNTIME_FAILURE = 1
EXIT_PARTIAL_SUCCESS = 3


def report_dry_run_summary(*, dry_run: bool, last_response: dict[str, Any] | None) -> None:
    """Print server dry-run estimates when the last ingest response contains them."""

    if not dry_run or not last_response:
        return
    summary = last_response.get("summary") or {}
    planned_points = summary.get("planned_points")
    if planned_points is not None:
        print(f"[dry-run] Estimated Qdrant points: {planned_points}")
    graph_preview = summary.get("graph_preview") or {}
    if graph_preview:
        planned_entities = graph_preview.get("planned_entities")
        planned_triplets = graph_preview.get("planned_triplets")
        if planned_entities is not None:
            print(f"[dry-run] Estimated Neo4j entities: {planned_entities}")
        if planned_triplets is not None:
            print(f"[dry-run] Estimated Neo4j relationships: {planned_triplets}")


def write_last_summary(
    *,
    summary_file: str | None,
    last_response: dict[str, Any] | None,
    write_summary_file,
) -> None:
    """Persist the last server summary when requested."""

    if not summary_file or not last_response:
        return
    summary = last_response.get("summary")
    if summary:
        write_summary_file(summary_file, summary)


def report_resume_state(
    *,
    resume_state_path: Path | None,
    dry_run: bool,
    resume_mode: bool,
    skipped_existing: int,
) -> None:
    """Print final resume-state details."""

    if resume_state_path is None:
        return
    if not dry_run:
        print(f"Resume state stored at {resume_state_path}")
    if resume_mode and skipped_existing:
        print(f"Skipped {skipped_existing} documents already ingested earlier.")


def determine_exit_code(
    *,
    dry_run: bool,
    estimate_only: bool,
    failed_batches: int,
    skipped_empty: int,
    sent: int,
) -> int:
    """Return the runner exit code for completed submission attempts."""

    if not dry_run and not estimate_only and failed_batches:
        return EXIT_RUNTIME_FAILURE
    if skipped_empty and sent == 0:
        return EXIT_PARTIAL_SUCCESS
    return 0


__all__ = [
    "determine_exit_code",
    "report_dry_run_summary",
    "report_resume_state",
    "write_last_summary",
]
