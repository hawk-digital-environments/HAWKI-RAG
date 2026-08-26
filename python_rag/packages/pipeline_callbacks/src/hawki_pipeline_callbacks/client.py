"""Signed, retry-safe callback client for Laravel-owned pipeline state."""

from __future__ import annotations

import hashlib
import hmac
import json
import time
from collections.abc import Mapping
from dataclasses import dataclass
from typing import Any, Protocol, cast

import httpx


class CallbackEvent(Protocol):
    """Structural type implemented by Pydantic callback contracts."""

    def model_dump(self, *, mode: str, by_alias: bool) -> dict[str, Any]:
        """Return a JSON-compatible event object."""


@dataclass(frozen=True, slots=True)
class LaravelCallbackSettings:
    endpoint: str
    secret: str
    timeout_seconds: float = 10.0
    retry_attempts: int = 3

    def __post_init__(self) -> None:
        if not self.endpoint.strip():
            raise ValueError("Laravel callback endpoint must not be empty.")
        if not self.secret:
            raise ValueError("Laravel callback secret must not be empty.")
        if self.timeout_seconds <= 0:
            raise ValueError("Laravel callback timeout must be positive.")
        if self.retry_attempts < 1:
            raise ValueError("Laravel callback retry attempts must be at least one.")


class LaravelCallbackError(RuntimeError):
    """Raised when Laravel did not durably accept a worker event."""


class LaravelCallbackClient:
    """Post immutable events with an HMAC over the exact request body."""

    def __init__(
        self,
        settings: LaravelCallbackSettings,
        *,
        client: httpx.Client | None = None,
    ) -> None:
        self.settings = settings
        self._client = client or httpx.Client(timeout=settings.timeout_seconds)
        self._owns_client = client is None

    def close(self) -> None:
        if self._owns_client:
            self._client.close()

    def __enter__(self) -> LaravelCallbackClient:
        return self

    def __exit__(self, *_args: object) -> None:
        self.close()

    def send(
        self,
        event: CallbackEvent | Mapping[str, Any],
        *,
        timestamp: int | None = None,
    ) -> dict[str, Any]:
        payload = self._event_payload(event)
        self._validate_identity(payload)
        try:
            body = json.dumps(
                payload,
                allow_nan=False,
                ensure_ascii=False,
                separators=(",", ":"),
                sort_keys=True,
            ).encode("utf-8")
        except (TypeError, ValueError):
            raise ValueError(
                "Worker callback event must contain only JSON-compatible values."
            ) from None
        signed_at = int(time.time()) if timestamp is None else int(timestamp)
        headers = self._headers(body, signed_at)

        last_failure = "unknown transport failure"
        for attempt in range(1, self.settings.retry_attempts + 1):
            try:
                response = self._client.post(
                    self.settings.endpoint,
                    content=body,
                    headers=headers,
                    timeout=self.settings.timeout_seconds,
                )
                conflict_code = self._conflict_code(response)
                if conflict_code == "pipeline_worker_event_id_collision":
                    raise LaravelCallbackError(
                        "Laravel rejected a reused callback event_id with a different payload."
                    )
                if conflict_code in {
                    "pipeline_worker_event_target_unavailable",
                    "pipeline_worker_event_state_unavailable",
                }:
                    last_failure = "HTTP 409"
                    if attempt >= self.settings.retry_attempts:
                        break
                    time.sleep(min(2 ** (attempt - 1), 5))
                    continue
                response.raise_for_status()
                try:
                    result = response.json()
                except ValueError:
                    raise LaravelCallbackError(
                        "Laravel callback returned invalid JSON."
                    ) from None
                if not isinstance(result, dict):
                    raise LaravelCallbackError(
                        "Laravel callback returned non-object JSON."
                    )
                return cast(dict[str, Any], result)
            except LaravelCallbackError:
                raise
            except (httpx.TransportError, httpx.HTTPStatusError) as exc:
                status = getattr(getattr(exc, "response", None), "status_code", None)
                retryable = status is None or int(status) >= 500 or int(status) == 429
                last_failure = self._safe_failure_description(exc, status=status)
                if not retryable or attempt >= self.settings.retry_attempts:
                    break
                time.sleep(min(2 ** (attempt - 1), 5))

        event_id = str(payload["event_id"])
        raise LaravelCallbackError(
            f"Laravel did not accept worker event {event_id}: {last_failure}."
        ) from None

    @staticmethod
    def _safe_failure_description(
        exc: httpx.TransportError | httpx.HTTPStatusError,
        *,
        status: int | None,
    ) -> str:
        """Describe a failed delivery without reflecting URLs, bodies, or secrets."""

        if status is not None:
            return f"HTTP {int(status)}"
        if isinstance(exc, httpx.TimeoutException):
            return "request timed out"
        return "network transport error"

    @staticmethod
    def _conflict_code(response: httpx.Response) -> str | None:
        """Return an allowlisted Laravel conflict code without reflecting its body."""

        if response.status_code != 409:
            return None
        try:
            payload = response.json()
        except ValueError:
            return None
        if not isinstance(payload, dict):
            return None
        code = payload.get("error")
        return code if isinstance(code, str) else None

    def _headers(self, body: bytes, timestamp: int) -> dict[str, str]:
        signing_input = str(timestamp).encode("ascii") + b"." + body
        digest = hmac.new(
            self.settings.secret.encode("utf-8"),
            signing_input,
            hashlib.sha256,
        ).hexdigest()
        return {
            "Content-Type": "application/json",
            "X-Hawki-Timestamp": str(timestamp),
            "X-Hawki-Signature": f"v1={digest}",
        }

    @staticmethod
    def _event_payload(event: CallbackEvent | Mapping[str, Any]) -> dict[str, Any]:
        if isinstance(event, Mapping):
            return dict(event)
        return dict(event.model_dump(mode="json", by_alias=True))

    @staticmethod
    def _validate_identity(payload: Mapping[str, Any]) -> None:
        if payload.get("schema_version") != 1:
            raise ValueError("Worker callback schema_version must be 1.")
        if not str(payload.get("event_id") or "").strip():
            raise ValueError("Worker callback event_id must not be empty.")


__all__ = [
    "CallbackEvent",
    "LaravelCallbackClient",
    "LaravelCallbackError",
    "LaravelCallbackSettings",
]
