"""Discovery and safety fixtures for live bridge/Qdrant tests."""

from __future__ import annotations

from dataclasses import dataclass
import os
from typing import Any, NoReturn

import pytest
import requests


_TRUTHY = frozenset({"1", "true", "yes", "on"})


def _required() -> bool:
    return os.environ.get("RAWKI_INTEGRATION_REQUIRED", "").strip().lower() in _TRUTHY


def unavailable(reason: str) -> NoReturn:
    """Skip unavailable Qdrant, or fail in required mode."""

    message = f"Live integration dependency unavailable: {reason}"
    if _required():
        pytest.fail(
            f"{message}. RAWKI_INTEGRATION_REQUIRED=1 requires every selected "
            "integration dependency to be configured and reachable."
        )
    pytest.skip(message)


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
        if normalized and normalized not in seen:
            seen.add(normalized)
            unique_values.append(normalized)
    return unique_values


@dataclass(frozen=True)
class LiveQdrant:
    """Reachable Qdrant endpoint and authenticated HTTP session."""

    base_url: str
    api_key: str | None
    session: Any


@pytest.fixture(scope="session")
def live_qdrant() -> LiveQdrant:
    """Discover Qdrant without creating or changing collections."""

    api_key = os.environ.get("QDRANT_API_KEY", "").strip() or None
    session = requests.Session()
    if api_key:
        session.headers.update({"api-key": api_key})

    configured_host = os.environ.get("QDRANT_HOST", "").strip()
    configured_scheme = os.environ.get("QDRANT_SCHEME", "http").strip() or "http"
    configured_port = os.environ.get("QDRANT_PORT", "6333").strip() or "6333"
    derived_url = (
        f"{configured_scheme}://{configured_host}:{configured_port}"
        if configured_host
        else ""
    )
    candidates = _unique(
        [
            os.environ.get("RAWKI_INTEGRATION_QDRANT_URL", ""),
            os.environ.get("QDRANT_HTTP_URL", ""),
            derived_url,
            "http://127.0.0.1:6333",
            "http://qdrant:6333",
        ]
    )
    failures: list[str] = []
    for candidate in candidates:
        try:
            response = session.get(f"{candidate}/collections", timeout=_probe_timeout())
        except requests.exceptions.RequestException as exc:
            failures.append(f"{candidate} ({type(exc).__name__})")
            continue
        if response.status_code == 200:
            yield LiveQdrant(candidate, api_key, session)
            session.close()
            return
        failures.append(f"{candidate} (HTTP {response.status_code})")

    session.close()
    unavailable(
        "Qdrant was not reachable with the configured API key; set "
        "RAWKI_INTEGRATION_QDRANT_URL/QDRANT_API_KEY or run inside the Compose "
        f"network (probes: {', '.join(failures) or 'none'})"
    )
