"""Converter profile, direct-extract, passthrough, and activity contracts."""

from __future__ import annotations

import io
import json
from pathlib import Path
from types import SimpleNamespace
from typing import Any
from unittest.mock import patch
import zipfile

import pytest
import requests
from temporalio.exceptions import ApplicationError

from hawki_artifact_store.local import LocalArtifactStore
from hawki_converter_worker.activities import convert as convert_activity
from hawki_converter_worker.adapters import direct_extract_client
from hawki_converter_worker.adapters.direct_extract_client import (
    RequestsDirectExtractClient,
)
from hawki_converter_worker.application.configuration import (
    build_converter_endpoint_config,
)
from hawki_converter_worker.application.dependencies import ConversionDependencies
from hawki_converter_worker.application.source_conversion import (
    execute_source_conversion,
)
from hawki_converter_worker.conversion.archive import unpack_converter_archive
from hawki_converter_worker.conversion.direct import convert_files_direct
from hawki_converter_worker.conversion.discovery import (
    SCRAPER_BOOKKEEPING_FILENAMES,
    find_raw_conversion_candidates,
)
from hawki_converter_worker.conversion.passthrough import (
    PASSTHROUGH_METADATA_FILENAME,
)
from hawki_converter_worker.domain.errors import (
    NonRetryableConverterResponseError,
    RetryableConverterRequestError,
)
from hawki_converter_worker.domain.models import ConverterEndpointConfig
from hawki_rag_contracts.pipeline.ingestion import ConvertActivityInput, ConvertResult


class UnsupportedResponse:
    status_code = 400
    ok = False
    content = b""
    text = '{"detail":"Unsupported file type. Supported types: .pdf, .doc"}'

    def json(self) -> dict[str, str]:
        return {"detail": "Unsupported file type. Supported types: .pdf, .doc"}


class ErrorResponse:
    ok = False
    content = b""

    def __init__(self, status_code: int, detail: str) -> None:
        self.status_code = status_code
        self.text = json.dumps({"detail": detail})

    def json(self) -> dict[str, str]:
        return {"detail": json.loads(self.text)["detail"]}


class ArchiveExtractClient:
    def extract(self, _raw_file: Path) -> bytes:
        return _markdown_archive("page.md", "# Converted\n\nContent")


def settings(*, start_path: str = "/extract") -> SimpleNamespace:
    return SimpleNamespace(
        converter_url="http://converter.test",
        converter_start_path=start_path,
        converter_status_path="/jobs/{job_id}",
        converter_token="",
        request_timeout_seconds=1,
        http_retry_attempts=1,
        poll_interval_seconds=1,
        poll_timeout_seconds=2,
    )


def endpoint_config(*, retry_attempts: int = 1) -> ConverterEndpointConfig:
    return ConverterEndpointConfig(
        base_url="http://converter.test",
        start_path="/extract",
        status_path="",
        token="file-converter-key",
        timeout_seconds=1,
        retry_attempts=retry_attempts,
        poll_interval_seconds=1,
        poll_timeout_seconds=2,
    )


def activity_payload(root: Path) -> dict[str, object]:
    raw_dir = root / "raw"
    raw_dir.mkdir(exist_ok=True)
    return {
        "workflow_input": {
            "source_id": "source-1",
            "storage": {"shared_root": str(root)},
            "raw_output_path": str(raw_dir),
            "markdown_output_path": str(root / "markdown"),
        },
        "scrape_result": {
            "source_id": "source-1",
            "status": "success",
            "raw_dir": str(raw_dir),
        },
    }


def failing_activity_payload(
    monkeypatch: pytest.MonkeyPatch,
    tmp_path: Path,
    failure: Exception,
) -> dict[str, object]:
    payload = activity_payload(tmp_path)

    def raise_failure(*_args: object, **_kwargs: object) -> ConvertResult:
        raise failure

    monkeypatch.setattr(convert_activity.ConverterSettings, "from_env", settings)
    monkeypatch.setattr(
        convert_activity,
        "build_conversion_dependencies",
        lambda: object(),
    )
    monkeypatch.setattr(
        convert_activity,
        "execute_source_conversion",
        raise_failure,
    )
    monkeypatch.setattr(
        convert_activity,
        "report_status",
        lambda *_args, **_kwargs: {"accepted": True},
    )
    return payload


def _markdown_archive(filename: str, content: str) -> bytes:
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, mode="w") as archive:
        archive.writestr(filename, content)
    return buffer.getvalue()


def test_converter_archive_rejects_paths_outside_its_output_directory(
    tmp_path: Path,
) -> None:
    archive = _markdown_archive("../escaped.md", "unsafe")

    with pytest.raises(RuntimeError, match="unsafe path"):
        unpack_converter_archive(archive, tmp_path / "output")

    assert not (tmp_path / "escaped.md").exists()


def test_custom_profile_overrides_converter_endpoint_config(tmp_path: Path) -> None:
    profile = tmp_path / "converter.json"
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
    config = build_converter_endpoint_config(
        {
            "converter_mode": "custom",
            "custom_converter_profile_path": str(profile),
        },
        settings(),
        artifact_store=LocalArtifactStore(tmp_path),
    )

    assert config.base_url == "https://converter.example.test"
    assert config.start_path == "/extract"
    assert config.status_path == "/jobs/{job_id}"
    assert config.token == "secret-token"
    assert config.uses_direct_extract is True


def test_custom_mode_requires_a_profile_path(tmp_path: Path) -> None:
    with pytest.raises(RuntimeError, match="without a converter profile path"):
        build_converter_endpoint_config(
            {"converter_mode": "custom"},
            settings(),
            artifact_store=LocalArtifactStore(tmp_path),
        )


@pytest.mark.parametrize(
    ("converter_url", "start_path"),
    [
        ("http://converter.test", "/api/convert/start"),
        ("http://converter.test/extract", "/ignored"),
    ],
)
def test_legacy_direct_routes_normalize_to_extract(
    tmp_path: Path,
    converter_url: str,
    start_path: str,
) -> None:
    configured_settings = settings(start_path=start_path)
    configured_settings.converter_url = converter_url

    config = build_converter_endpoint_config(
        {},
        configured_settings,
        artifact_store=LocalArtifactStore(tmp_path),
    )

    assert config.base_url == "http://converter.test"
    assert config.start_path == "/extract"
    assert config.uses_direct_extract is True


def test_unsupported_image_creates_raganything_passthrough(tmp_path: Path) -> None:
    raw_dir = tmp_path / "raw"
    markdown_dir = tmp_path / "markdown"
    raw_dir.mkdir()
    image = raw_dir / "photo.jpg"
    image.write_bytes(b"fake jpeg bytes")
    client = RequestsDirectExtractClient(endpoint_config(retry_attempts=3))

    with patch.object(
        direct_extract_client.requests,
        "post",
        return_value=UnsupportedResponse(),
    ) as post:
        result = convert_files_direct(
            "source-image",
            str(raw_dir),
            str(markdown_dir),
            artifact_store=LocalArtifactStore(tmp_path),
            extract_client=client,
        )

    handoff = next(markdown_dir.rglob("content_markdown.md"))
    metadata = json.loads(
        (handoff.parent / PASSTHROUGH_METADATA_FILENAME).read_text(encoding="utf-8")
    )
    assert post.call_count == 1
    assert result.status.value == "success"
    assert result.passthrough_files == (str(image.resolve()),)
    assert metadata["converter_fallback"] == "raganything_passthrough"
    assert metadata["image_path"] == str(image.resolve())


@pytest.mark.parametrize("status_code", [400, 401, 403, 404, 405, 422])
def test_direct_extract_does_not_retry_permanent_client_errors(
    tmp_path: Path,
    status_code: int,
) -> None:
    raw_file = tmp_path / "document.pdf"
    raw_file.write_bytes(b"pdf")
    client = RequestsDirectExtractClient(endpoint_config(retry_attempts=3))

    with patch.object(
        direct_extract_client.requests,
        "post",
        return_value=ErrorResponse(status_code, "request rejected"),
    ) as post:
        with pytest.raises(
            NonRetryableConverterResponseError,
            match=rf"Converter request failed \[{status_code}\]",
        ):
            client.extract(raw_file)

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
    client = RequestsDirectExtractClient(endpoint_config(retry_attempts=3))

    with (
        patch.object(
            direct_extract_client.requests,
            "post",
            side_effect=[failure, failure, failure],
        ) as post,
        patch.object(direct_extract_client.time, "sleep") as sleep,
    ):
        with pytest.raises(RetryableConverterRequestError):
            client.extract(raw_file)

    assert post.call_count == 3
    assert sleep.call_count == 2


def test_converter_activity_marks_permanent_response_error_non_retryable(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    failure = NonRetryableConverterResponseError(
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
    failure = RetryableConverterRequestError("converter timed out")
    payload = failing_activity_payload(monkeypatch, tmp_path, failure)

    with pytest.raises(RetryableConverterRequestError) as caught:
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


def test_direct_extract_skips_scraper_bookkeeping_files(tmp_path: Path) -> None:
    raw_dir = tmp_path / "raw"
    markdown_dir = tmp_path / "markdown"
    raw_dir.mkdir()
    for name in SCRAPER_BOOKKEEPING_FILENAMES:
        (raw_dir / name).write_text("{}", encoding="utf-8")

    result = convert_files_direct(
        "source-empty",
        str(raw_dir),
        str(markdown_dir),
        artifact_store=LocalArtifactStore(tmp_path),
        extract_client=ArchiveExtractClient(),
    )

    assert result.status.value == "failed"
    assert result.converted_files == ()
    assert result.markdown_files_created == 0
    assert "No files were found" in str(result.error_details)


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
        find_raw_conversion_candidates(store, raw_dir)

    escape.unlink()
    outside_raw = shared_root / "other" / "outside.pdf"
    outside_raw.parent.mkdir()
    outside_raw.write_bytes(b"outside raw directory")
    escape.symlink_to(outside_raw)
    with pytest.raises(ValueError, match="outside its directory"):
        find_raw_conversion_candidates(store, raw_dir)


def test_converter_activity_returns_typed_markdown_artifacts(
    tmp_path: Path,
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    raw_dir = tmp_path / "raw"
    raw_dir.mkdir()
    (raw_dir / "document.pdf").write_bytes(b"pdf")
    callbacks: list[dict[str, Any]] = []
    dependencies = ConversionDependencies(
        artifact_store_factory=LocalArtifactStore,
        direct_extract_client_factory=lambda _config: ArchiveExtractClient(),
        external_converter_client_factory=lambda _config: pytest.fail(
            "direct conversion must not create an external client"
        ),
    )

    monkeypatch.setattr(convert_activity.ConverterSettings, "from_env", settings)
    monkeypatch.setattr(
        convert_activity,
        "build_conversion_dependencies",
        lambda: dependencies,
    )
    monkeypatch.setattr(
        convert_activity,
        "report_status",
        lambda *_args, **kwargs: callbacks.append(kwargs) or {"accepted": True},
    )

    result = convert_activity.inspect_and_convert_files(activity_payload(tmp_path))

    artifact = result["artifacts"][0]
    assert artifact["uri"].endswith("/page.md")
    assert artifact["source_id"] == "source-1"
    assert artifact["source_artifact_uri"] == str(raw_dir)
    assert len(artifact["content_hash"]) == 64
    assert callbacks[-1]["artifacts"][0].uri.endswith("/page.md")


def test_application_use_case_routes_non_direct_profiles_to_external_client(
    tmp_path: Path,
) -> None:
    raw_dir = tmp_path / "raw"
    markdown_dir = tmp_path / "markdown"
    raw_dir.mkdir()
    markdown_dir.mkdir()
    (markdown_dir / "external.md").write_text("# External", encoding="utf-8")
    calls: list[dict[str, Any]] = []

    class ExternalClient:
        def start_and_wait(self, payload: dict[str, Any]) -> dict[str, Any]:
            calls.append(payload)
            return {
                "status": "completed",
                "external_job_id": "job-1",
                "markdown_dir": str(markdown_dir),
                "markdown_files_created": 1,
            }

    dependencies = ConversionDependencies(
        artifact_store_factory=LocalArtifactStore,
        direct_extract_client_factory=lambda _config: pytest.fail(
            "external conversion must not create a direct client"
        ),
        external_converter_client_factory=lambda _config: ExternalClient(),
    )
    request = ConvertActivityInput.model_validate(activity_payload(tmp_path))

    result = execute_source_conversion(
        request,
        settings=settings(start_path="/jobs/start"),
        dependencies=dependencies,
    )

    assert result.status.value == "success"
    assert result.external_job_id == "job-1"
    assert result.markdown_files_created == 1
    assert result.artifacts[0].uri == str(markdown_dir / "external.md")
    assert calls == [
        {
            "source_id": "source-1",
            "raw_dir": str(raw_dir),
            "markdown_dir": str(markdown_dir),
        }
    ]
