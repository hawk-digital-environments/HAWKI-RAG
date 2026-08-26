"""Build typed converter endpoint settings from workflow configuration."""

from __future__ import annotations

import json
import logging
from typing import Any

from hawki_rag_contracts.ingestion import IngestionStatus

from hawki_converter_worker.domain.models import ConverterEndpointConfig
from hawki_converter_worker.domain.ports import ConverterArtifactStorePort
from hawki_converter_worker.settings import ConverterSettings

logger = logging.getLogger(__name__)


def build_converter_endpoint_config(
    workflow_input: dict[str, Any],
    settings: ConverterSettings,
    *,
    artifact_store: ConverterArtifactStorePort,
) -> ConverterEndpointConfig:
    """Resolve workflow overrides and normalize legacy direct-extract routes."""

    external = dict(workflow_input.get("external_services") or {})
    values: dict[str, object] = {
        "base_url": str(external.get("converter_url") or settings.converter_url),
        "start_path": str(
            external.get("converter_start_path") or settings.converter_start_path
        ),
        "status_path": str(
            external.get("converter_status_path") or settings.converter_status_path
        ),
        "token": str(external.get("converter_token") or settings.converter_token),
    }
    values.update(_load_custom_converter_profile(workflow_input, artifact_store))

    base_url, start_path = _normalize_direct_extract_route(
        str(values["base_url"]),
        str(values["start_path"]),
    )
    return ConverterEndpointConfig(
        base_url=base_url,
        start_path=start_path,
        status_path=str(values["status_path"]),
        token=str(values["token"]),
        timeout_seconds=settings.request_timeout_seconds,
        retry_attempts=max(1, settings.http_retry_attempts),
        poll_interval_seconds=settings.poll_interval_seconds,
        poll_timeout_seconds=settings.poll_timeout_seconds,
    )


def should_fallback_to_direct_extract(
    exc: RuntimeError,
    config: ConverterEndpointConfig,
) -> bool:
    """Return whether a missing legacy start route should use `/extract`."""

    return "/api/convert/start" in config.start_path and "404 Client Error" in str(exc)


def normalize_converter_status(payload: dict[str, Any]) -> IngestionStatus:
    """Normalize external converter status variants to pipeline statuses."""

    status = str(payload.get("status") or "running").strip().lower()
    if status in {"completed", "complete", "succeeded", "success", "done", "ready"}:
        return IngestionStatus.SUCCESS
    if status in {"failed", "error", "timeout", "cancelled", "canceled"}:
        return IngestionStatus.FAILED
    return IngestionStatus(status)


def _load_custom_converter_profile(
    workflow_input: dict[str, Any],
    artifact_store: ConverterArtifactStorePort,
) -> dict[str, str]:
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
        "start_path": _profile_string(
            payload,
            "converter_start_path",
            "start_path",
        )
        or "/extract",
        "status_path": _profile_string(
            payload,
            "converter_status_path",
            "status_path",
        ),
        "token": _profile_string(payload, "converter_token", "token", "api_key"),
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


def _normalize_direct_extract_route(base_url: str, start_path: str) -> tuple[str, str]:
    normalized_base_url = base_url.rstrip("/")
    normalized_start_path = "/" + start_path.strip().lstrip("/")

    # The HAWKI file-converter exposes /extract directly, not a start/status API.
    if normalized_base_url.endswith("/extract"):
        return normalized_base_url.removesuffix("/extract"), "/extract"
    if normalized_start_path == "/api/convert/start":
        return normalized_base_url, "/extract"
    return normalized_base_url, normalized_start_path


__all__ = [
    "build_converter_endpoint_config",
    "normalize_converter_status",
    "should_fallback_to_direct_extract",
]
