"""Converter orchestration and direct-extract implementation."""

from __future__ import annotations

from dataclasses import dataclass
import hashlib
import io
import json
import logging
from pathlib import Path
import shutil
import time
from typing import Any
from urllib.parse import urljoin
import zipfile

import requests

from hawki_artifact_store.local import LocalArtifactStore
from hawki_converter_worker.settings import ConverterSettings

logger = logging.getLogger(__name__)

PASSTHROUGH_METADATA_FILENAME = "rawki_passthrough.json"
SCRAPER_BOOKKEEPING_FILENAMES = frozenset(
    {
        "crawler.log",
        "job_state.json",
        "summary.json",
        "urls_index.json",
    }
)


class DirectExtractUnsupportedFileError(RuntimeError):
    """The converter rejected a file that the indexer may parse directly."""


class NonRetryableConverterResponseError(RuntimeError):
    """The converter permanently rejected a valid HTTP request."""


class RetryableConverterRequestError(RuntimeError):
    """A transient converter request failed after its bounded retries."""


@dataclass(frozen=True)
class DirectExtractRequest:
    url: str
    headers: dict[str, str]
    timeout_seconds: float
    retry_attempts: int


@dataclass(frozen=True)
class ConvertedFileResult:
    source_path: str
    markdown_files_created: int
    used_passthrough: bool


def converter_service_config(
    workflow_input: dict[str, Any],
    settings: ConverterSettings,
    *,
    artifact_store: LocalArtifactStore,
) -> dict[str, Any]:
    external = dict(workflow_input.get("external_services") or {})
    base_url = str(external.get("converter_url") or settings.converter_url)
    start_path = str(
        external.get("converter_start_path") or settings.converter_start_path
    )
    status_path = str(
        external.get("converter_status_path") or settings.converter_status_path
    )
    token = str(external.get("converter_token") or settings.converter_token)

    config = {
        "base_url": base_url,
        "start_path": start_path,
        "status_path": status_path,
        "token": token,
        "timeout_seconds": settings.request_timeout_seconds,
        "retry_attempts": settings.http_retry_attempts,
        "poll_interval_seconds": settings.poll_interval_seconds,
        "poll_timeout_seconds": settings.poll_timeout_seconds,
    }

    config.update(_custom_converter_profile_config(workflow_input, artifact_store))

    return config


def _custom_converter_profile_config(
    workflow_input: dict[str, Any],
    artifact_store: LocalArtifactStore,
) -> dict[str, Any]:
    profile_path = workflow_input.get("custom_converter_profile_path")
    if not isinstance(profile_path, str) or not profile_path.strip():
        if str(workflow_input.get("converter_mode") or "").lower() == "custom":
            raise RuntimeError(
                "Custom converter mode was requested without a converter profile path."
            )
        return {}

    path = artifact_store.resolve(profile_path)
    if not path.is_file():
        raise RuntimeError(f"Custom converter profile was not found: {path}")

    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        raise RuntimeError(f"Custom converter profile is unreadable: {path}") from exc

    if not isinstance(payload, dict):
        raise RuntimeError(f"Custom converter profile must be a JSON object: {path}")

    profile = {
        "base_url": _profile_string(payload, "converter_url", "base_url", "url"),
        "start_path": _profile_string(payload, "converter_start_path", "start_path")
        or "/extract",
        "status_path": _profile_string(payload, "converter_status_path", "status_path")
        or "",
        "token": _profile_string(payload, "converter_token", "token", "api_key") or "",
    }

    if not profile["base_url"]:
        raise RuntimeError(f"Custom converter profile is missing converter_url: {path}")

    logger.info(
        "converter:custom_profile_loaded path=%s start_path=%s has_status_path=%s has_token=%s",
        path,
        profile["start_path"],
        bool(profile["status_path"]),
        bool(profile["token"]),
    )
    return profile


def _profile_string(payload: dict[str, Any], *keys: str) -> str:
    for key in keys:
        value = payload.get(key)
        if isinstance(value, (str, int, float)) and str(value).strip():
            return str(value).strip()
    return ""


def _status(payload: dict[str, Any]) -> str:
    status = str(payload.get("status") or "running").strip().lower()
    if status in {"completed", "complete", "succeeded", "success", "done", "ready"}:
        return "success"
    if status in {"failed", "error", "timeout", "cancelled", "canceled"}:
        return "failed"
    return status


def _uses_direct_converter(service_config: dict[str, Any]) -> bool:
    return str(service_config.get("start_path") or "").strip().strip("/") == "extract"


def _normalize_direct_converter_start_path(
    service_config: dict[str, Any],
) -> dict[str, Any]:
    normalized = dict(service_config)
    base_url = str(normalized.get("base_url") or "").rstrip("/")
    start_path = "/" + str(normalized.get("start_path") or "").strip().lstrip("/")

    # The HAWKI file-converter exposes /extract directly, not a start/status API.
    if base_url.endswith("/extract"):
        normalized["base_url"] = base_url.removesuffix("/extract")
        normalized["start_path"] = "/extract"
    elif start_path == "/api/convert/start":
        normalized["start_path"] = "/extract"

    return normalized


def _should_fallback_to_extract_api(
    exc: RuntimeError, service_config: dict[str, Any]
) -> bool:
    start_path = str(service_config.get("start_path") or "")

    return "/api/convert/start" in start_path and "404 Client Error" in str(exc)


def _convert_files_with_extract_api(
    service_config: dict[str, Any],
    source_id: str,
    raw_dir: str,
    markdown_dir: str,
    *,
    artifact_store: LocalArtifactStore,
) -> dict[str, Any]:
    raw_root = _local_directory(artifact_store, raw_dir, "raw")
    markdown_root = _local_directory(
        artifact_store,
        markdown_dir,
        "markdown",
        must_exist=False,
    )
    markdown_root.mkdir(parents=True, exist_ok=True)

    candidates = _raw_conversion_candidates(artifact_store, raw_root)

    if not candidates:
        return {
            "source_id": source_id,
            "external_job_id": None,
            "markdown_dir": str(markdown_root),
            "markdown_files_created": 0,
            "converted_files": [],
            "skipped_files": [],
            "status": "failed",
            "error_details": "No files were found for the converter.",
        }

    converted_files: list[str] = []
    passthrough_files: list[str] = []
    markdown_files_created = 0
    for raw_file in candidates:
        source_path = str(raw_root / raw_file.relative_to(raw_root))
        result = _convert_candidate_with_extract_api(
            service_config, raw_file, markdown_root, source_path
        )
        markdown_files_created += result.markdown_files_created
        converted_files.append(result.source_path)
        if result.used_passthrough:
            passthrough_files.append(result.source_path)

    return {
        "source_id": source_id,
        "external_job_id": None,
        "markdown_dir": str(markdown_root),
        "markdown_files_created": markdown_files_created,
        "converted_files": converted_files,
        "passthrough_files": passthrough_files,
        "skipped_files": [],
        "status": "success" if markdown_files_created > 0 else "failed",
        "error_details": None
        if markdown_files_created > 0
        else "Converter did not produce Markdown files.",
    }


def _convert_candidate_with_extract_api(
    service_config: dict[str, Any],
    raw_file: Path,
    markdown_root: Path,
    source_path: str,
) -> ConvertedFileResult:
    output_dir = markdown_root / _converter_output_dir_name(raw_file)
    if output_dir.exists():
        shutil.rmtree(output_dir)
    output_dir.mkdir(parents=True, exist_ok=True)

    try:
        created = _extract_single_file(service_config, raw_file, output_dir)
        return ConvertedFileResult(source_path, created, False)
    except DirectExtractUnsupportedFileError as exc:
        created = _write_raganything_passthrough(raw_file, output_dir, exc)
        logger.info(
            "converter:direct_extract_passthrough file=%s reason=%s",
            raw_file,
            exc,
        )
        return ConvertedFileResult(source_path, created, True)


def _extract_single_file(
    service_config: dict[str, Any], raw_file: Path, output_dir: Path
) -> int:
    request = _direct_extract_request(service_config)
    last_error: Exception | None = None

    for attempt in range(1, request.retry_attempts + 1):
        try:
            with raw_file.open("rb") as handle:
                response = requests.post(
                    request.url,
                    headers=request.headers,
                    files={"file": (raw_file.name, handle)},
                    timeout=request.timeout_seconds,
                )

            if not response.ok:
                error = _response_error(response)
                if _is_unsupported_direct_extract_response(response.status_code, error):
                    raise DirectExtractUnsupportedFileError(
                        f"Direct converter does not support {raw_file.name}: {error}"
                    )
                message = f"Converter request failed [{response.status_code}]: {error}"
                if _is_retryable_converter_status(response.status_code):
                    raise RetryableConverterRequestError(message)
                raise NonRetryableConverterResponseError(message)

            return _unpack_converter_zip(response.content, output_dir)
        except (
            DirectExtractUnsupportedFileError,
            NonRetryableConverterResponseError,
        ):
            raise
        except (
            requests.Timeout,
            requests.ConnectionError,
            RetryableConverterRequestError,
        ) as exc:
            last_error = exc
            if attempt >= request.retry_attempts:
                break
            time.sleep(min(2 ** (attempt - 1), 10))

    raise RetryableConverterRequestError(
        f"Converter extract request failed for {raw_file.name}: {last_error}"
    ) from last_error


def _direct_extract_request(service_config: dict[str, Any]) -> DirectExtractRequest:
    base_url = str(service_config["base_url"]).rstrip("/") + "/"
    start_path = str(service_config.get("start_path") or "/extract").lstrip("/")
    token = str(service_config.get("token") or "")

    return DirectExtractRequest(
        url=urljoin(base_url, start_path),
        headers={"Authorization": f"Bearer {token}"} if token else {},
        timeout_seconds=float(service_config.get("timeout_seconds") or 30),
        retry_attempts=max(1, int(service_config.get("retry_attempts") or 1)),
    )


def _write_raganything_passthrough(
    raw_file: Path, output_dir: Path, error: Exception
) -> int:
    output_dir.mkdir(parents=True, exist_ok=True)
    raw_path = str(raw_file.resolve())
    file_hash = _sha256_file(raw_file)
    markdown_path = output_dir / "content_markdown.md"
    markdown_path.write_text(
        "\n".join(
            [
                f"# {raw_file.name}",
                "",
                "The direct converter could not extract Markdown for this file.",
                "The original file is attached for RAG-Anything/MinerU native parsing or OCR during graph ingestion.",
                "",
                f"Original file: `{raw_file.name}`",
                f"Original SHA-256: `{file_hash}`",
                "",
            ]
        ),
        encoding="utf-8",
    )

    metadata: dict[str, Any] = {
        "source_format": "raganything_passthrough",
        "original_filename": raw_file.name,
        "original_path": raw_path,
        "source_file": raw_path,
        "file_path": raw_path,
        "converted_path": str(markdown_path.resolve()),
        "converter_fallback": "raganything_passthrough",
        "converter_error": str(error),
        "original_sha256": file_hash,
    }
    if _is_image_file(raw_file):
        metadata["image_path"] = raw_path
        metadata["images"] = [raw_path]

    (output_dir / PASSTHROUGH_METADATA_FILENAME).write_text(
        json.dumps(metadata, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    return 1


def _is_unsupported_direct_extract_response(status_code: int, error: str) -> bool:
    message = error.lower()
    return status_code == 400 and "unsupported file type" in message


def _is_retryable_converter_status(status_code: int) -> bool:
    return status_code >= 500 or status_code in {408, 429}


def _is_image_file(path: Path) -> bool:
    return path.suffix.lower() in {
        ".bmp",
        ".gif",
        ".jpeg",
        ".jpg",
        ".png",
        ".tif",
        ".tiff",
        ".webp",
    }


def _sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _unpack_converter_zip(content: bytes, output_dir: Path) -> int:
    archive_data = io.BytesIO(content)
    if not zipfile.is_zipfile(archive_data):
        raise RuntimeError("Converter returned a non-ZIP response.")

    archive_data.seek(0)
    markdown_files_created = 0
    output_root = output_dir.resolve()
    with zipfile.ZipFile(archive_data) as archive:
        for member in archive.infolist():
            if member.is_dir():
                continue

            member_path = Path(member.filename)
            if member_path.is_absolute() or ".." in member_path.parts:
                raise RuntimeError(
                    f"Converter ZIP contained an unsafe path: {member.filename}"
                )

            target = (output_root / member_path).resolve()
            try:
                target.relative_to(output_root)
            except ValueError as exc:
                raise RuntimeError(
                    f"Converter ZIP path escaped output directory: {member.filename}"
                ) from exc

            target.parent.mkdir(parents=True, exist_ok=True)
            with archive.open(member) as source, target.open("wb") as destination:
                shutil.copyfileobj(source, destination)

            if target.suffix.lower() in {".md", ".markdown"}:
                markdown_files_created += 1

    return markdown_files_created


def _local_directory(
    artifact_store: LocalArtifactStore,
    path: str,
    label: str,
    *,
    must_exist: bool = True,
) -> Path:
    root = artifact_store.resolve(path)
    if must_exist and (not root.exists() or not root.is_dir()):
        raise RuntimeError(f"{label.capitalize()} directory was not found: {root}")

    return root


def _raw_conversion_candidates(
    artifact_store: LocalArtifactStore,
    raw_root: Path,
) -> list[Path]:
    skip_bookkeeping = _looks_like_scraper_output_dir(raw_root)
    candidates: list[Path] = []
    for path in sorted(raw_root.rglob("*")):
        if not path.is_file() or (
            skip_bookkeeping and path.name in SCRAPER_BOOKKEEPING_FILENAMES
        ):
            continue

        resolved = artifact_store.resolve(path)
        artifact_store.relative_path(resolved, raw_root)
        candidates.append(resolved)

    return candidates


def _looks_like_scraper_output_dir(raw_root: Path) -> bool:
    return (raw_root / "job_state.json").is_file() or (
        raw_root / "urls_index.json"
    ).is_file()


def _converter_output_dir_name(raw_file: Path) -> str:
    safe_stem = "".join(
        char.lower() if char.isalnum() else "-" for char in raw_file.stem
    ).strip("-")
    digest = hashlib.sha256(str(raw_file.resolve()).encode("utf-8")).hexdigest()[:8]

    return f"{safe_stem or 'document'}-{digest}"


def _response_error(response: requests.Response) -> str:
    try:
        payload = response.json()
    except ValueError:
        return response.text[:500]

    return str(payload)[:500]
