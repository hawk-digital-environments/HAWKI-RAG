"""Converter profile, direct-extract, and passthrough contracts."""

from __future__ import annotations

import json
from pathlib import Path
from tempfile import TemporaryDirectory
from types import SimpleNamespace
from unittest.mock import patch

import pytest
import requests
from temporalio.exceptions import ApplicationError

from hawki_artifact_store.local import LocalArtifactStore
from hawki_converter_worker.activities import convert as convert_activity
from hawki_converter_worker.conversion import service


class UnsupportedResponse:
    status_code = 400
    ok = False
    content = b""
    text = '{"detail":"Unsupported file type. Supported types: .pdf, .doc"}'

    def json(self) -> dict[str, str]:
        return {"detail": "Unsupported file type. Supported types: .pdf, .doc"}

    def raise_for_status(self) -> None:
        raise RuntimeError("400 Client Error")


class ErrorResponse:
    ok = False
    content = b""

    def __init__(self, status_code: int, detail: str) -> None:
        self.status_code = status_code
        self.text = json.dumps({"detail": detail})

    def json(self) -> dict[str, str]:
        return {"detail": json.loads(self.text)["detail"]}

    def raise_for_status(self) -> None:
        raise requests.HTTPError(f"{self.status_code} Server Error")


def settings() -> SimpleNamespace:
    return SimpleNamespace(
        converter_url="http://converter.test",
        converter_start_path="/extract",
        converter_status_path="",
        converter_token="",
        request_timeout_seconds=1,
        http_retry_attempts=1,
        poll_interval_seconds=1,
        poll_timeout_seconds=2,
    )


def failing_activity_payload(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path: Path,
    failure: Exception,
) -> dict[str, object]:
    raw_dir = tmp_path / "raw"
    raw_dir.mkdir()
    markdown_dir = tmp_path / "markdown"

    def raise_failure(*_args: object, **_kwargs: object) -> dict[str, object]:
        raise failure

    monkeypatch.setattr(convert_activity.ConverterSettings, "from_env", settings)
    monkeypatch.setattr(
        service,
        "converter_service_config",
        lambda *_args, **_kwargs: {"start_path": "/extract"},
    )
    monkeypatch.setattr(
        service,
        "_normalize_direct_converter_start_path",
        lambda config: config,
    )
    monkeypatch.setattr(service, "_uses_direct_converter", lambda _config: True)
    monkeypatch.setattr(service, "_convert_files_with_extract_api", raise_failure)
    monkeypatch.setattr(
        convert_activity,
        "report_status",
        lambda *_args, **_kwargs: {"accepted": True},
    )

    return {
        "workflow_input": {
            "source_id": "source-1",
            "storage": {"shared_root": str(tmp_path)},
            "raw_output_path": str(raw_dir),
            "markdown_output_path": str(markdown_dir),
        },
        "scrape_result": {
            "source_id": "source-1",
            "status": "success",
            "raw_dir": str(raw_dir),
        },
    }


def test_custom_profile_overrides_converter_service_config() -> None:
    with TemporaryDirectory() as temporary:
        profile = Path(temporary) / "converter.json"
        profile.write_text(
            json.dumps(
                {
                    "converter_url": "https://converter.example.test",
                    "converter_start_path": "/extract",
                    "converter_status_path": "/jobs/{job_id}",
                    "converter_token": "secret-token",
                }
            ),
            encoding="utf-8",
        )
        config = service.converter_service_config(
            {
                "converter_mode": "custom",
                "custom_converter_profile_path": str(profile),
            },
            settings(),
            artifact_store=LocalArtifactStore(temporary),
        )

    assert config["base_url"] == "https://converter.example.test"
    assert config["start_path"] == "/extract"
    assert config["status_path"] == "/jobs/{job_id}"
    assert config["token"] == "secret-token"


def test_custom_mode_requires_a_profile_path(tmp_path: Path) -> None:
    try:
        service.converter_service_config(
            {"converter_mode": "custom"},
            settings(),
            artifact_store=LocalArtifactStore(tmp_path),
        )
    except RuntimeError as exc:
        assert "without a converter profile path" in str(exc)
    else:
        raise AssertionError("custom converter mode must require a profile")


def test_unsupported_image_creates_raganything_passthrough() -> None:
    with TemporaryDirectory() as temporary:
        root = Path(temporary)
        raw_dir = root / "raw"
        markdown_dir = root / "markdown"
        raw_dir.mkdir()
        image = raw_dir / "photo.jpg"
        image.write_bytes(b"fake jpeg bytes")
        config = {
            "base_url": "http://converter.test",
            "start_path": "/extract",
            "token": "file-converter-key",
            "timeout_seconds": 1,
            "retry_attempts": 3,
        }

        with patch.object(
            service.requests, "post", return_value=UnsupportedResponse()
        ) as post:
            result = service._convert_files_with_extract_api(
                config,
                "source-image",
                str(raw_dir),
                str(markdown_dir),
                artifact_store=LocalArtifactStore(root),
            )

        handoff = next(markdown_dir.rglob("content_markdown.md"))
        metadata = json.loads(
            (handoff.parent / service.PASSTHROUGH_METADATA_FILENAME).read_text()
        )

    assert post.call_count == 1
    assert result["status"] == "success"
    assert result["passthrough_files"] == [str(image)]
    assert metadata["converter_fallback"] == "raganything_passthrough"
    assert metadata["image_path"] == str(image.resolve())


@pytest.mark.parametrize("status_code", [400, 401, 403, 404, 405, 422])
def test_direct_extract_does_not_retry_permanent_client_errors(
    tmp_path: Path,
    status_code: int,
) -> None:
    raw_file = tmp_path / "document.pdf"
    raw_file.write_bytes(b"pdf")
    output_dir = tmp_path / "output"
    config = {
        "base_url": "http://converter.test",
        "start_path": "/extract",
        "timeout_seconds": 1,
        "retry_attempts": 3,
    }

    with patch.object(
        service.requests,
        "post",
        return_value=ErrorResponse(status_code, "request rejected"),
    ) as post:
        with pytest.raises(
            service.NonRetryableConverterResponseError,
            match=rf"Converter request failed \[{status_code}\]",
        ):
            service._extract_single_file(config, raw_file, output_dir)

    assert post.call_count == 1


@pytest.mark.parametrize(
    "failure",
    [
        requests.Timeout("converter timed out"),
        requests.ConnectionError("converter disconnected"),
        ErrorResponse(408, "request timeout"),
        ErrorResponse(429, "rate limited"),
        ErrorResponse(502, "bad gateway"),
    ],
    ids=["timeout", "connection", "http-408", "http-429", "http-502"],
)
def test_direct_extract_retries_transient_converter_failures(
    tmp_path: Path,
    failure: Exception | ErrorResponse,
) -> None:
    raw_file = tmp_path / "document.pdf"
    raw_file.write_bytes(b"pdf")
    output_dir = tmp_path / "output"
    config = {
        "base_url": "http://converter.test",
        "start_path": "/extract",
        "timeout_seconds": 1,
        "retry_attempts": 3,
    }

    with (
        patch.object(
            service.requests,
            "post",
            side_effect=[failure, failure, failure],
        ) as post,
        patch.object(service.time, "sleep") as sleep,
    ):
        with pytest.raises(service.RetryableConverterRequestError):
            service._extract_single_file(config, raw_file, output_dir)

    assert post.call_count == 3
    assert sleep.call_count == 2


def test_converter_activity_marks_permanent_response_error_non_retryable(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    failure = service.NonRetryableConverterResponseError(
        "Converter request failed [405]: request rejected"
    )
    payload = failing_activity_payload(monkeypatch, tmp_path, failure)

    with pytest.raises(ApplicationError) as caught:
        convert_activity.inspect_and_convert_files(payload)

    assert caught.value.type == "NonRetryableConverterResponseError"
    assert caught.value.non_retryable is True
    assert caught.value.__cause__ is failure


def test_converter_activity_preserves_retryable_request_error(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    failure = service.RetryableConverterRequestError("converter timed out")
    payload = failing_activity_payload(monkeypatch, tmp_path, failure)

    with pytest.raises(service.RetryableConverterRequestError) as caught:
        convert_activity.inspect_and_convert_files(payload)

    assert caught.value is failure


def test_converter_reports_shared_storage_initialization_failure(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    callback_statuses = []
    missing_root = tmp_path / "missing-shared"
    monkeypatch.setattr(convert_activity.ConverterSettings, "from_env", settings)
    monkeypatch.setattr(
        convert_activity,
        "report_status",
        lambda *_args, **kwargs: (
            callback_statuses.append(kwargs["status"]) or {"accepted": True}
        ),
    )

    with pytest.raises(FileNotFoundError, match="Shared artifact root"):
        convert_activity.inspect_and_convert_files(
            {
                "workflow_input": {
                    "source_id": "source-1",
                    "storage": {"shared_root": str(missing_root)},
                    "raw_output_path": str(missing_root / "raw"),
                    "markdown_output_path": str(missing_root / "markdown"),
                },
                "scrape_result": {
                    "source_id": "source-1",
                    "status": "success",
                    "raw_dir": str(missing_root / "raw"),
                },
            }
        )

    assert callback_statuses == [
        convert_activity.PipelineStageStatus.RUNNING,
        convert_activity.PipelineStageStatus.FAILED,
    ]


def test_direct_extract_skips_scraper_bookkeeping_files() -> None:
    with TemporaryDirectory() as temporary:
        root = Path(temporary)
        raw_dir = root / "raw"
        markdown_dir = root / "markdown"
        raw_dir.mkdir()
        for name in service.SCRAPER_BOOKKEEPING_FILENAMES:
            (raw_dir / name).write_text("{}", encoding="utf-8")

        result = service._convert_files_with_extract_api(
            {
                "base_url": "http://converter.test",
                "start_path": "/extract",
                "timeout_seconds": 1,
                "retry_attempts": 1,
            },
            "source-empty",
            str(raw_dir),
            str(markdown_dir),
            artifact_store=LocalArtifactStore(root),
        )

    assert result["status"] == "failed"
    assert result["converted_files"] == []
    assert result["markdown_files_created"] == 0
    assert "No files were found" in result["error_details"]


def test_converter_candidates_cannot_follow_symlinks_outside_raw_directory(
    tmp_path: Path,
) -> None:
    shared_root = tmp_path / "shared"
    raw_dir = shared_root / "raw"
    raw_dir.mkdir(parents=True)
    store = LocalArtifactStore(shared_root)
    escape = raw_dir / "escape.pdf"

    outside_root = tmp_path / "outside.pdf"
    outside_root.write_bytes(b"outside shared root")
    escape.symlink_to(outside_root)
    with pytest.raises(ValueError, match="shared root"):
        service._raw_conversion_candidates(store, raw_dir)

    escape.unlink()
    outside_raw = shared_root / "other" / "outside.pdf"
    outside_raw.parent.mkdir()
    outside_raw.write_bytes(b"outside raw directory")
    escape.symlink_to(outside_raw)
    with pytest.raises(ValueError, match="outside its directory"):
        service._raw_conversion_candidates(store, raw_dir)


def test_converter_activity_returns_typed_markdown_artifacts(
    tmp_path: Path,
    monkeypatch,
) -> None:
    raw_dir = tmp_path / "raw"
    markdown_dir = tmp_path / "markdown"
    raw_dir.mkdir()
    markdown_dir.mkdir()
    markdown_file = markdown_dir / "page.md"
    markdown_file.write_text("# Converted\n\nContent", encoding="utf-8")
    callbacks = []

    monkeypatch.setattr(convert_activity.ConverterSettings, "from_env", settings)
    monkeypatch.setattr(
        service,
        "converter_service_config",
        lambda *_args, **_kwargs: {"start_path": "/extract"},
    )
    monkeypatch.setattr(
        service,
        "_normalize_direct_converter_start_path",
        lambda config: config,
    )
    monkeypatch.setattr(service, "_uses_direct_converter", lambda _config: True)
    monkeypatch.setattr(
        service,
        "_convert_files_with_extract_api",
        lambda *_args, **_kwargs: {
            "status": "success",
            "markdown_dir": str(markdown_dir),
            "markdown_files_created": 1,
        },
    )
    monkeypatch.setattr(
        convert_activity,
        "report_status",
        lambda *_args, **kwargs: callbacks.append(kwargs) or {"accepted": True},
    )

    result = convert_activity.inspect_and_convert_files(
        {
            "workflow_input": {
                "source_id": "source-1",
                "storage": {"shared_root": str(tmp_path)},
                "raw_output_path": str(raw_dir),
                "markdown_output_path": str(markdown_dir),
            },
            "scrape_result": {
                "source_id": "source-1",
                "status": "success",
                "raw_dir": str(raw_dir),
            },
        }
    )

    artifact = result["artifacts"][0]
    assert artifact["uri"] == str(markdown_file)
    assert artifact["source_id"] == "source-1"
    assert artifact["source_artifact_uri"] == str(raw_dir)
    assert len(artifact["content_hash"]) == 64
    assert callbacks[-1]["artifacts"][0].uri == str(markdown_file)
