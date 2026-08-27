"""Discovery and safety fixtures for live model-provider tests."""

from __future__ import annotations

from collections.abc import Callable
from dataclasses import dataclass
import os
from typing import NoReturn
from urllib.parse import urlsplit

import pytest
import requests


_TRUTHY = frozenset({"1", "true", "yes", "on"})


def _required() -> bool:
    return os.environ.get("RAWKI_INTEGRATION_REQUIRED", "").strip().lower() in _TRUTHY


def unavailable(reason: str) -> NoReturn:
    """Skip an unavailable model runtime, or fail in required mode."""

    message = f"Live integration dependency unavailable: {reason}"
    if _required():
        pytest.fail(
            f"{message}. RAWKI_INTEGRATION_REQUIRED=1 requires every selected "
            "integration dependency to be configured and reachable."
        )
    pytest.skip(message)


@pytest.fixture
def integration_unavailable() -> Callable[[str], NoReturn]:
    """Expose the optional-versus-required policy to provider tests."""

    return unavailable


def _probe_timeout() -> float:
    raw_value = os.environ.get("RAWKI_INTEGRATION_PROBE_TIMEOUT", "1.5")
    try:
        return max(0.25, float(raw_value))
    except ValueError:
        return 1.5


def _unique(values: list[str]) -> list[str]:
    seen: set[str] = set()
    unique_values: list[str] = []
    for value in values:
        normalized = str(value or "").strip().rstrip("/")
        if not normalized or normalized in seen:
            continue
        seen.add(normalized)
        unique_values.append(normalized)
    return unique_values


def _env_values(*names: str) -> list[str]:
    return [os.environ.get(name, "") for name in names]


def _model_matches(available: set[str], configured: str) -> bool:
    configured_name = configured.strip()
    if configured_name in available:
        return True
    return ":" not in configured_name and f"{configured_name}:latest" in available


@dataclass(frozen=True)
class LiveOllama:
    """Reachable Ollama API and the models already installed there."""

    api_url: str
    models: set[str]

    def has_model(self, configured: str) -> bool:
        return _model_matches(self.models, configured)


@pytest.fixture(scope="session")
def live_ollama() -> LiveOllama:
    """Discover Ollama and list models without triggering a download."""

    candidates = _unique(
        _env_values("RAWKI_INTEGRATION_OLLAMA_API_URL", "OLLAMA_API_URL")
        + [
            "http://127.0.0.1:11434/api",
            "http://hawki_ollama:11434/api",
            "http://ollama:11434/api",
        ]
    )
    failures: list[str] = []
    for api_url in candidates:
        try:
            response = requests.get(f"{api_url}/tags", timeout=_probe_timeout())
        except requests.exceptions.RequestException as exc:
            failures.append(f"{api_url} ({type(exc).__name__})")
            continue
        if response.status_code != 200:
            failures.append(f"{api_url} (HTTP {response.status_code})")
            continue
        try:
            payload = response.json()
        except ValueError:
            failures.append(f"{api_url} (invalid JSON)")
            continue
        model_rows = payload.get("models") if isinstance(payload, dict) else None
        models = {
            str(row.get("name") or row.get("model") or "").strip()
            for row in (model_rows or [])
            if isinstance(row, dict) and (row.get("name") or row.get("model"))
        }
        return LiveOllama(api_url=api_url, models=models)

    unavailable(
        "Ollama was not reachable; set RAWKI_INTEGRATION_OLLAMA_API_URL/"
        "OLLAMA_API_URL or run inside the Compose network "
        f"(probes: {', '.join(failures) or 'none'})"
    )


@dataclass(frozen=True)
class LiveLiteLLM:
    """Reachable OpenAI-compatible LiteLLM API and advertised aliases."""

    api_url: str
    api_key: str
    models: set[str]

    def has_model(self, configured: str) -> bool:
        return configured.strip() in self.models


def _litellm_api_url(value: str) -> str:
    normalized = value.strip().rstrip("/")
    if not normalized:
        return ""
    path = urlsplit(normalized).path.rstrip("/")
    return normalized if path.endswith("/v1") else f"{normalized}/v1"


@pytest.fixture(scope="session")
def live_litellm() -> LiveLiteLLM:
    """Discover an explicitly enabled LiteLLM gateway and its aliases."""

    api_key = os.environ.get("LITELLM_API_KEY", "").strip()
    candidates = _unique(
        [
            _litellm_api_url(value)
            for value in _env_values(
                "RAWKI_INTEGRATION_LITELLM_API_URL",
                "LITELLM_API_URL",
            )
        ]
        + ["http://127.0.0.1:4000/v1", "http://litellm:4000/v1"]
    )
    headers = {"Authorization": f"Bearer {api_key}"} if api_key else {}
    failures: list[str] = []
    for api_url in candidates:
        try:
            response = requests.get(
                f"{api_url}/models",
                headers=headers,
                timeout=_probe_timeout(),
            )
        except requests.exceptions.RequestException as exc:
            failures.append(f"{api_url} ({type(exc).__name__})")
            continue
        if response.status_code != 200:
            failures.append(f"{api_url} (HTTP {response.status_code})")
            continue
        try:
            payload = response.json()
        except ValueError:
            failures.append(f"{api_url} (invalid JSON)")
            continue
        model_rows = payload.get("data") if isinstance(payload, dict) else None
        models = {
            str(row.get("id") or "").strip()
            for row in (model_rows or [])
            if isinstance(row, dict) and row.get("id")
        }
        return LiveLiteLLM(api_url=api_url, api_key=api_key, models=models)

    unavailable(
        "the optional LiteLLM profile was not reachable/authenticated; start it "
        "with the Compose 'litellm' profile and set "
        "RAWKI_INTEGRATION_LITELLM_API_URL/LITELLM_API_KEY "
        f"(probes: {', '.join(failures) or 'none'})"
    )
