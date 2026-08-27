"""Scraper activity, upload, heartbeat, and callback scenarios."""

from __future__ import annotations

from datetime import datetime, timezone
from pathlib import Path
from tempfile import TemporaryDirectory
from types import SimpleNamespace
from typing import Any

import pytest

from hawki_artifact_store.local import LocalArtifactStore
from hawki_scraper_worker.activities.scrape import (
    activity_execution,
    heartbeat_external_job_id,
    run_scrape_activity,
)
from hawki_scraper_worker.adapters.artifact_store import LocalUploadArtifactStager
from hawki_scraper_worker.adapters.status_callback import (
    ActivityExecution,
    ScraperStatusReporter,
)


class _Service:
    def __init__(self, result: dict[str, Any] | Exception) -> None:
        self.result = result
        self.resume_job_id: str | None = None

    def scrape(
        self,
        _workflow_input: dict[str, Any],
        *,
        resume_external_job_id: str | None,
        progress_callback,
    ) -> dict[str, Any]:
        self.resume_job_id = resume_external_job_id
        progress_callback(resume_external_job_id or "new-external-job")
        if isinstance(self.result, Exception):
            raise self.result
        return self.result


class _Reporter:
    def __init__(self) -> None:
        self.events: list[tuple[str, object]] = []

    def report_running(self, _input, _execution, *, raw_dir: str):
        self.events.append(("running", raw_dir))

    def report_result(self, _input, _execution, result):
        self.events.append(("result", result))

    def report_exception(self, _input, _execution, exc):
        self.events.append(("exception", exc))


class _Sender:
    def __init__(self) -> None:
        self.payloads: list[dict[str, Any]] = []
        self.closed = False

    def send(self, event):
        self.payloads.append(dict(event))
        return {"ok": True}

    def close(self) -> None:
        self.closed = True


def _workflow_input() -> dict[str, Any]:
    return {
        "source_id": "source-a",
        "source_url": "https://example.test",
        "job_id": "job-a",
        "task_id": "task-a",
        "raw_output_path": "/shared/sources/source-a/raw",
        "metadata": {"request": {"metadata": {"max_pages": 2}}},
    }


def _activity_info(*, heartbeat_details=()) -> SimpleNamespace:
    return SimpleNamespace(
        workflow_id="ingest-source-source-a",
        workflow_run_id="run-a",
        activity_id="activity-a",
        attempt=2,
        heartbeat_details=heartbeat_details,
    )


def test_activity_resumes_heartbeat_job_and_reports_running_then_completed() -> None:
    service = _Service(
        {
            "source_id": "source-a",
            "raw_dir": "/shared/sources/source-a/raw",
            "status": "success",
            "files_found": 2,
            "pages_crawled": 2,
        }
    )
    reporter = _Reporter()
    heartbeats: list[object] = []

    result = run_scrape_activity(
        _workflow_input(),
        service=service,  # type: ignore[arg-type]
        reporter=reporter,  # type: ignore[arg-type]
        activity_info=_activity_info(
            heartbeat_details=({"external_job_id": "existing-job"},)
        ),
        heartbeat_sender=heartbeats.append,
        task_queue="rag-scraper-task-queue",
    )

    assert result["status"] == "success"
    assert service.resume_job_id == "existing-job"
    assert heartbeats == [{"external_job_id": "existing-job"}]
    assert [event[0] for event in reporter.events] == ["running", "result"]


def test_activity_reports_exception_and_reraises() -> None:
    service = _Service(RuntimeError("crawler unavailable"))
    reporter = _Reporter()

    with pytest.raises(RuntimeError, match="crawler unavailable"):
        run_scrape_activity(
            _workflow_input(),
            service=service,  # type: ignore[arg-type]
            reporter=reporter,  # type: ignore[arg-type]
            activity_info=_activity_info(),
            heartbeat_sender=lambda _details: None,
            task_queue="rag-scraper-task-queue",
        )

    assert [event[0] for event in reporter.events] == ["running", "exception"]


def test_heartbeat_and_temporal_identity_parsing_preserve_retry_context() -> None:
    assert heartbeat_external_job_id(("old-job",)) == "old-job"
    assert heartbeat_external_job_id(({"external_job_id": 42},)) == "42"
    assert heartbeat_external_job_id(()) is None
    assert activity_execution(_activity_info()) == ActivityExecution(
        workflow_id="ingest-source-source-a",
        run_id="run-a",
        temporal_activity_id="activity-a",
        attempt=2,
    )


def test_upload_stager_recreates_artifact_directories_and_preserves_result_shape() -> (
    None
):
    with TemporaryDirectory() as temporary:
        root = Path(temporary).resolve()
        upload = root / "upload.pdf"
        upload.write_bytes(b"pdf")
        raw_dir = root / "source" / "raw"
        markdown_dir = root / "source" / "markdown"
        raw_dir.mkdir(parents=True)
        markdown_dir.mkdir(parents=True)
        (raw_dir / "stale.txt").write_text("stale", encoding="utf-8")
        (markdown_dir / "stale.md").write_text("stale", encoding="utf-8")

        result = LocalUploadArtifactStager().stage(
            {
                "upload": {"local_path": str(upload), "target_name": "source.pdf"},
                "markdown_output_path": str(markdown_dir),
            },
            "source-a",
            str(raw_dir),
            LocalArtifactStore(root),
        )

        assert result is not None
        assert result["status"] == "success"
        assert result["files_found"] == 1
        assert Path(result["uploaded_file"]).read_bytes() == b"pdf"
        assert not (raw_dir / "stale.txt").exists()
        assert not (markdown_dir / "stale.md").exists()


def test_upload_stager_validates_every_input_before_resetting_directories(
    tmp_path: Path,
) -> None:
    upload = tmp_path / "upload.pdf"
    upload.write_bytes(b"pdf")
    raw_dir = tmp_path / "source" / "raw"
    markdown_dir = tmp_path / "source" / "markdown"
    raw_dir.mkdir(parents=True)
    markdown_dir.mkdir(parents=True)
    raw_marker = raw_dir / "keep.txt"
    markdown_marker = markdown_dir / "keep.md"
    raw_marker.write_text("keep", encoding="utf-8")
    markdown_marker.write_text("keep", encoding="utf-8")

    with pytest.raises(RuntimeError, match="plain file name"):
        LocalUploadArtifactStager().stage(
            {
                "upload": {"local_path": str(upload), "target_name": "../escape.pdf"},
                "markdown_output_path": str(markdown_dir),
            },
            "source-a",
            str(raw_dir),
            LocalArtifactStore(tmp_path),
        )

    assert raw_marker.read_text(encoding="utf-8") == "keep"
    assert markdown_marker.read_text(encoding="utf-8") == "keep"


def test_upload_stager_rejects_a_source_inside_a_directory_it_would_reset(
    tmp_path: Path,
) -> None:
    raw_dir = tmp_path / "source" / "raw"
    markdown_dir = tmp_path / "source" / "markdown"
    raw_dir.mkdir(parents=True)
    markdown_dir.mkdir(parents=True)
    upload = raw_dir / "upload.pdf"
    upload.write_bytes(b"must survive")

    with pytest.raises(RuntimeError, match="must be outside"):
        LocalUploadArtifactStager().stage(
            {
                "upload": {"local_path": str(upload)},
                "markdown_output_path": str(markdown_dir),
            },
            "source-a",
            str(raw_dir),
            LocalArtifactStore(tmp_path),
        )

    assert upload.read_bytes() == b"must survive"


def test_status_reporter_builds_laravel_compatible_typed_events() -> None:
    sender = _Sender()
    reporter = ScraperStatusReporter(
        sender,
        clock=lambda: datetime(2026, 8, 3, 12, 0, tzinfo=timezone.utc),
    )
    execution = ActivityExecution(
        workflow_id="ingest-source-source-a",
        run_id="run-a",
        temporal_activity_id="activity-a",
        attempt=2,
    )

    reporter.report_running(
        _workflow_input(),
        execution,
        raw_dir="/shared/sources/source-a/raw",
    )
    reporter.report_result(
        _workflow_input(),
        execution,
        {
            "status": "failed",
            "raw_dir": "/shared/sources/source-a/raw",
            "files_found": 1,
            "pages_crawled": 1,
            "max_pages": 2,
            "error_details": "Authorization: Bearer super-secret-token",
        },
    )

    running, failed = sender.payloads
    assert running["status"] == "running"
    assert running["activity_id"] == "scrape_source"
    assert running["timestamp"] == "2026-08-03T12:00:00Z"
    assert running["phase"] == "scrape_source"
    assert running["artifacts"] == [
        {
            "uri": "/shared/sources/source-a/raw",
            "relative_path": None,
            "sha256": None,
            "size_bytes": None,
            "media_type": "inode/directory",
        }
    ]
    assert failed["status"] == "failed"
    assert failed["errors"] == [
        {
            "code": "scrape_failed",
            "message": "Authorization=<redacted>",
            "retryable": True,
        }
    ]
    assert failed["error_details"] == "Authorization=<redacted>"
    assert failed["metrics"] == {"pages_crawled": 1, "files_found": 1}
    assert running["event_id"] != failed["event_id"]
    assert len(running["event_id"]) < 191
