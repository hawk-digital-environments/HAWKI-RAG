"""Keep the documented deployment environment valid for the runtime loaders."""

from pathlib import Path
from unittest.mock import Mock

import pytest
import yaml

from hawki_backend.managed_documents.artifacts import UploadStorage
from hawki_backend.query.settings import load_query_settings
from hawki_backend.settings import load_auth_settings, load_database_settings
from hawki_backend.text_ingestion.settings import load_text_ingestion_settings
from hawki_backend.url_ingestion.settings import load_url_ingestion_settings

REPOSITORY = Path(__file__).resolve().parents[2]


def test_environment_example_matches_runtime_settings() -> None:
    """Operator examples must pass startup validation without contacting services."""
    example = Path(__file__).resolve().parents[1] / ".env.example"
    environ = {}
    for line in example.read_text(encoding="utf-8").splitlines():
        if line.startswith("RAG_BACKEND_"):
            name, value = line.split("=", 1)
            environ[name] = value

    assert load_auth_settings(environ) is not None
    assert load_database_settings(environ) is not None
    resolver = Mock()
    query = load_query_settings(environ, dataset_scope_resolver=resolver)
    assert query is not None
    assert query.dataset_scope_resolver is resolver
    assert not query.datasets
    assert query.models_for("ollama") is not None


@pytest.mark.parametrize(
    "service",
    [
        "rag_backend",
        "scraper_worker",
        "converter_worker",
        "indexer_worker",
    ],
)
def test_artifact_services_share_the_configured_persisted_root(service: str) -> None:
    compose = yaml.safe_load(
        (REPOSITORY / "docker-compose.yml").read_text(encoding="utf-8")
    )
    environ = dict(
        line.split("=", 1)
        for line in (REPOSITORY / ".env.example")
        .read_text(encoding="utf-8")
        .splitlines()
        if line and not line.startswith("#") and "=" in line
    )
    managed_root = UploadStorage(environ["RAG_TEMPORAL_SHARED_ROOT"]).root
    text_root = Path(load_text_ingestion_settings(environ).shared_root)
    url_root = Path(load_url_ingestion_settings(environ).shared_root)
    assert managed_root == text_root == url_root == Path("/shared")
    assert f"shared_storage:{managed_root}" in compose["services"][service]["volumes"]
    assert compose["volumes"]["shared_storage"]["name"] == "rawki_shared_storage"


def test_backend_reuses_worker_permissions_after_volume_initialization() -> None:
    compose = yaml.safe_load(
        (REPOSITORY / "docker-compose.yml").read_text(encoding="utf-8")
    )
    backend = compose["services"]["rag_backend"]
    worker = compose["services"]["converter_worker"]
    assert backend["group_add"] == worker["group_add"] == ["${PGID}"]
    assert "hawki_rag_app" not in backend["depends_on"]
    backend_image = (REPOSITORY / "python_backend/Dockerfile").read_text(
        encoding="utf-8"
    )
    worker_image = (REPOSITORY / "python_rag/Dockerfile").read_text(encoding="utf-8")
    for shared_setup in (
        "COPY docker/python_rag/80-rawki-runtime-permissions.sh /container/custom/entrypoint/80-rawki-runtime-permissions.sh",
    ):
        assert shared_setup in backend_image
        assert shared_setup in worker_image


@pytest.mark.parametrize(
    "compose_file",
    [
        REPOSITORY / "docker-compose.yml",
        REPOSITORY / "_docker_production/docker-compose.yml",
    ],
)
def test_backend_and_infrastructure_stay_private(compose_file):
    services = yaml.safe_load(compose_file.read_text())["services"]
    assert {
        "hawki_rag_frontend",
        "hawki_rag_ui_gateway",
        "temporal-ui",
        "hawki_rag_app",
        "hawki_rag_bridge",
        "migrator",
    }.isdisjoint(services)
    backend = services["rag_backend"]
    assert not backend.get("ports")
    assert set(backend.get("networks", ["default"])) == {"default"}
    assert all("environment" not in service for service in services.values())
    runtime_env = (compose_file.parent / backend["env_file"][-1]).read_text()
    assert "RAG_BACKEND_SERVICE_ONLY=true" in runtime_env.splitlines()
    assert "RAG_BACKEND_REQUIRE_READY_SCHEMA=true" in runtime_env.splitlines()
    assert "RAG_BACKEND_DATASET_CLEANUP_ENABLED=false" in runtime_env.splitlines()
    assert "RAG_BACKEND_QUERY_DATASET_SOURCE=database" in runtime_env.splitlines()
    assert "RAG_UI_" not in runtime_env
    for role in ("postgres", "qdrant", "neo4j", "temporal"):
        assert not services[role].get("ports")
