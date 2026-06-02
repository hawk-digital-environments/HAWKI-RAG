from __future__ import annotations

import json
import os
import time
import urllib.error
import urllib.request
from dataclasses import dataclass
from typing import Any, Optional


@dataclass
class LaravelClient:
    base_url: str
    api_token: str = ""
    timeout: int = 15

    def get_task(self, task_id: str) -> dict[str, Any]:
        return self._request("GET", f"/api/pipeline/tasks/{task_id}")

    def get_jobs(self, task_id: str) -> dict[str, Any]:
        return self._request("GET", f"/api/pipeline/tasks/{task_id}/jobs")

    def complete_if_idle(self, task_id: str) -> dict[str, Any]:
        return self._request("POST", f"/api/pipeline/tasks/{task_id}/complete-if-idle")

    def update_status(self, task_id: str, status: str, metadata: dict[str, Any] | None = None) -> dict[str, Any]:
        return self._request(
            "POST",
            f"/api/pipeline/tasks/{task_id}/status",
            {"status": status, "metadata": metadata or {}},
        )

    def _request(self, method: str, path: str, payload: Optional[dict[str, Any]] = None) -> dict[str, Any]:
        body = None if payload is None else json.dumps(payload).encode("utf-8")
        request = urllib.request.Request(
            f"{self.base_url.rstrip('/')}/{path.lstrip('/')}",
            data=body,
            method=method,
            headers=self._headers(payload is not None),
        )

        try:
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                text = response.read().decode("utf-8")
                return json.loads(text) if text else {}
        except urllib.error.HTTPError as exc:
            text = exc.read().decode("utf-8", errors="replace")
            raise RuntimeError(f"Laravel API returned HTTP {exc.code}: {text}") from exc

    def _headers(self, has_body: bool) -> dict[str, str]:
        headers = {"Accept": "application/json"}
        if has_body:
            headers["Content-Type"] = "application/json"
        if self.api_token:
            headers["Authorization"] = f"Bearer {self.api_token}"
        return headers


def client_from_env(
    laravel_base_url: Optional[str] = None,
    api_token: Optional[str] = None,
) -> LaravelClient:
    return LaravelClient(
        base_url=laravel_base_url or os.getenv("LARAVEL_BASE_URL", "http://hawki_rag_app"),
        api_token=api_token if api_token is not None else os.getenv("LARAVEL_API_TOKEN", ""),
    )


def wait_seconds(seconds: int) -> None:
    time.sleep(max(1, seconds))
