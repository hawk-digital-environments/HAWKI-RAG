"""Adapters around external scraper and converter services."""

from __future__ import annotations

from collections.abc import Callable
import logging
import time
from typing import Any, cast
from urllib.parse import urljoin

import requests

logger = logging.getLogger(__name__)

_SUCCESS_STATUSES = {"completed", "complete", "succeeded", "success", "done", "ready"}
_FAILED_STATUSES = {"failed", "error", "cancelled", "canceled"}


class ExternalJobClient:
    """Small polling client for an external start/status job API."""

    def __init__(
        self,
        *,
        base_url: str,
        start_path: str,
        status_path: str,
        token: str = "",
        timeout_seconds: float = 30.0,
        retry_attempts: int = 3,
        poll_interval_seconds: float = 5.0,
        poll_timeout_seconds: float = 43200.0,
    ) -> None:
        self.base_url = base_url.rstrip("/") + "/"
        self.start_path = start_path.lstrip("/")
        self.status_path = status_path.lstrip("/")
        self.token = token
        self.timeout_seconds = timeout_seconds
        self.retry_attempts = max(1, retry_attempts)
        self.poll_interval_seconds = max(0.5, poll_interval_seconds)
        self.poll_timeout_seconds = max(
            self.poll_interval_seconds, poll_timeout_seconds
        )
        self.session = requests.Session()

    def start_and_wait(
        self,
        payload: dict[str, Any],
        *,
        resume_job_id: str | None = None,
        progress_callback: Callable[[str], None] | None = None,
    ) -> dict[str, Any]:
        """Start a job, or resume polling one recorded by a previous attempt."""
        if resume_job_id:
            job_id = resume_job_id
            last_status: dict[str, Any] = {"external_job_id": job_id}
        else:
            started = self._request("POST", self.start_path, json=payload)
            job_id = self._job_id(started)
            if not job_id:
                raise RuntimeError("External service did not return a job id.")
            last_status = dict(started)

        if progress_callback is not None:
            progress_callback(job_id)

        deadline = time.monotonic() + self.poll_timeout_seconds
        while time.monotonic() < deadline:
            snapshot = self._request("GET", self.status_path.format(job_id=job_id))
            last_status = snapshot
            if progress_callback is not None:
                progress_callback(job_id)
            status = self._status(snapshot)
            if status in _SUCCESS_STATUSES:
                snapshot["external_job_id"] = job_id
                snapshot["status"] = status
                return snapshot
            if status in _FAILED_STATUSES:
                snapshot["external_job_id"] = job_id
                snapshot["status"] = status
                return snapshot
            time.sleep(self.poll_interval_seconds)

        last_status["external_job_id"] = job_id
        last_status["status"] = "timeout"
        last_status["error"] = f"External job {job_id} did not complete before timeout."
        return last_status

    def _request(self, method: str, path: str, **kwargs: object) -> dict[str, Any]:
        url = urljoin(self.base_url, path)
        headers = cast(dict[str, str], kwargs.pop("headers", {}))
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"

        last_error: Exception | None = None
        for attempt in range(1, self.retry_attempts + 1):
            try:
                response = self.session.request(
                    method,
                    url,
                    timeout=self.timeout_seconds,
                    headers=headers,
                    **kwargs,
                )
                response.raise_for_status()
                data = response.json()
                if not isinstance(data, dict):
                    raise RuntimeError("External service returned non-object JSON.")
                return data
            except Exception as exc:
                last_error = exc
                if attempt >= self.retry_attempts:
                    break
                time.sleep(min(2 ** (attempt - 1), 10))

        raise RuntimeError(
            f"External service request failed: {method} {url}: {last_error}"
        ) from last_error

    @staticmethod
    def _job_id(payload: dict[str, Any]) -> str | None:
        for key in ("external_job_id", "job_id", "jobId", "id", "task_id", "taskId"):
            value = payload.get(key)
            if isinstance(value, (str, int)) and str(value).strip():
                return str(value)
        return None

    @staticmethod
    def _status(payload: dict[str, Any]) -> str:
        for key in ("status", "state", "phase"):
            value = payload.get(key)
            if isinstance(value, str) and value.strip():
                return value.strip().lower()
        return "running"


__all__ = ["ExternalJobClient"]
