"""Shared discovery and safety fixtures for opt-in live integration tests.

The fixtures probe already-running services; they never start containers or
download models.  A missing service skips its tests during normal development.
Set ``RAWKI_INTEGRATION_REQUIRED=1`` in a release job to turn those skips into
actionable failures.
"""

from __future__ import annotations

from dataclasses import dataclass
import importlib
import os
from pathlib import Path
import sys
from typing import Any, Callable, NoReturn
from urllib.parse import urlsplit

import pytest


PYTHON_RAG_ROOT = Path(__file__).resolve().parents[2]
if str(PYTHON_RAG_ROOT) not in sys.path:
    sys.path.insert(0, str(PYTHON_RAG_ROOT))


_TRUTHY = frozenset({"1", "true", "yes", "on"})


def _required() -> bool:
    return os.environ.get("RAWKI_INTEGRATION_REQUIRED", "").strip().lower() in _TRUTHY


def unavailable(reason: str) -> NoReturn:
    """Skip an unavailable live dependency, or fail in required/release mode."""

    message = f"Live integration dependency unavailable: {reason}"
    if _required():
        pytest.fail(
            f"{message}. RAWKI_INTEGRATION_REQUIRED=1 requires every selected "
            "integration dependency to be configured and reachable."
        )
    pytest.skip(message)


@pytest.fixture
def integration_unavailable() -> Callable[[str], NoReturn]:
    """Expose the shared optional-versus-required policy to individual tests."""

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


def _requests_module() -> Any:
    try:
        return importlib.import_module("requests")
    except ImportError:
        unavailable("the 'requests' package is not installed; install python_rag/requirements.txt")


@dataclass(frozen=True)
class LiveQdrant:
    """Reachable Qdrant endpoint and authenticated HTTP session."""

    base_url: str
    api_key: str | None
    session: Any


@pytest.fixture(scope="session")
def live_qdrant() -> LiveQdrant:
    """Discover a Qdrant endpoint without creating or changing collections."""

    requests = _requests_module()
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
        _env_values("RAWKI_INTEGRATION_QDRANT_URL", "QDRANT_HTTP_URL")
        + [derived_url, "http://127.0.0.1:6333", "http://qdrant:6333"]
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
        "RAWKI_INTEGRATION_QDRANT_URL/QDRANT_API_KEY or run inside the Compose network "
        f"(probes: {', '.join(failures) or 'none'})"
    )


@dataclass(frozen=True)
class LiveNeo4j:
    """Verified Neo4j settings plus a driver reserved for test cleanup."""

    settings: Any
    driver: Any


@pytest.fixture(scope="session")
def live_neo4j() -> LiveNeo4j:
    """Connect to a real Neo4j database using configured credentials."""

    try:
        neo4j = importlib.import_module("neo4j")
    except ImportError:
        unavailable("the 'neo4j' package is not installed; install python_rag/requirements.txt")

    from infrastructure.graph.neo4j_settings import Neo4jSettings

    user = os.environ.get("NEO4J_USER", os.environ.get("NEO4J_USERNAME", "neo4j")).strip()
    password = os.environ.get("NEO4J_PASSWORD", "password").strip()
    database = os.environ.get("NEO4J_DATABASE", "").strip() or None
    candidates = _unique(
        _env_values("RAWKI_INTEGRATION_NEO4J_URI", "NEO4J_URI", "NEO4J_BOLT_URL")
        + [
            "bolt://127.0.0.1:7687",
            "bolt://hawki_rag_neo4j:7687",
            "bolt://neo4j:7687",
        ]
    )
    failures: list[str] = []
    for uri in candidates:
        driver = None
        try:
            driver = neo4j.GraphDatabase.driver(
                uri,
                auth=(user or "neo4j", password),
                connection_timeout=_probe_timeout(),
            )
            driver.verify_connectivity()
            with driver.session(database=database) as session:
                session.run("RETURN 1 AS ready").consume()
        except Exception as exc:  # The driver exposes several transport/auth subclasses.
            failures.append(f"{uri} ({type(exc).__name__})")
            if driver is not None:
                driver.close()
            continue

        settings = Neo4jSettings(
            uri=uri,
            user=user or "neo4j",
            password=password,
            database=database,
            retry_attempts=1,
            retry_attempts_by_operation={
                "neo4j.upsert_triplets": 1,
                "neo4j.delete_by_doc_id": 1,
                "neo4j.fetch_related": 1,
                "neo4j.search_structural": 1,
            },
            log_latency=False,
            perf_log=False,
        )
        yield LiveNeo4j(settings=settings, driver=driver)
        driver.close()
        return

    unavailable(
        "Neo4j was not reachable with the configured credentials; set "
        "RAWKI_INTEGRATION_NEO4J_URI, NEO4J_USER, and NEO4J_PASSWORD or run inside "
        f"the Compose network (probes: {', '.join(failures) or 'none'})"
    )


def _model_matches(available: set[str], configured: str) -> bool:
    configured_name = configured.strip()
    if configured_name in available:
        return True
    if ":" not in configured_name and f"{configured_name}:latest" in available:
        return True
    return False


@dataclass(frozen=True)
class LiveOllama:
    """Reachable Ollama API and the models already installed there."""

    api_url: str
    models: set[str]

    def has_model(self, configured: str) -> bool:
        return _model_matches(self.models, configured)


@pytest.fixture(scope="session")
def live_ollama() -> LiveOllama:
    """Discover Ollama and list models without triggering a model download."""

    requests = _requests_module()
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
        "Ollama was not reachable; set RAWKI_INTEGRATION_OLLAMA_API_URL/OLLAMA_API_URL "
        f"or run inside the Compose network (probes: {', '.join(failures) or 'none'})"
    )


@dataclass(frozen=True)
class LiveLiteLLM:
    """Reachable OpenAI-compatible LiteLLM API and advertised model aliases."""

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
    """Discover an explicitly enabled LiteLLM gateway and its model aliases."""

    requests = _requests_module()
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
        "the optional LiteLLM profile was not reachable/authenticated; start it with "
        "the Compose 'litellm' profile and set RAWKI_INTEGRATION_LITELLM_API_URL/"
        f"LITELLM_API_KEY (probes: {', '.join(failures) or 'none'})"
    )
