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
        if line.startswith("HAWKI_BACKEND_"):
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
        "hawki_backend",
        "hawki_rag_app",
        "hawki-rag-temporal-scraper-worker",
        "hawki-rag-temporal-converter-worker",
        "hawki-rag-indexer-worker",
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
    managed_root = UploadStorage(environ["HAWKI_RAG_TEMPORAL_SHARED_ROOT"]).root
    text_root = Path(load_text_ingestion_settings(environ).shared_root)
    url_root = Path(load_url_ingestion_settings(environ).shared_root)
    assert managed_root == text_root == url_root == Path("/shared")
    assert f"shared_storage:{managed_root}" in compose["services"][service]["volumes"]
    assert compose["volumes"]["shared_storage"]["name"] == "rawki_shared_storage"


def test_backend_reuses_worker_permissions_after_volume_initialization() -> None:
    compose = yaml.safe_load(
        (REPOSITORY / "docker-compose.yml").read_text(encoding="utf-8")
    )
    backend = compose["services"]["hawki_backend"]
    worker = compose["services"]["hawki-rag-temporal-converter-worker"]
    assert backend["group_add"] == worker["group_add"] == ["${PGID}"]
    assert backend["depends_on"]["hawki_rag_app"]["condition"] == "service_healthy"
    backend_image = (REPOSITORY / "python_backend/Dockerfile").read_text(
        encoding="utf-8"
    )
    worker_image = (REPOSITORY / "python_rag/Dockerfile").read_text(encoding="utf-8")
    for shared_setup in (
        "COPY docker/python_rag/10-rawki-runtime.env /container/custom/env/10-rawki-runtime.env",
        "COPY docker/python_rag/80-rawki-runtime-permissions.sh /container/custom/entrypoint/80-rawki-runtime-permissions.sh",
    ):
        assert shared_setup in backend_image
        assert shared_setup in worker_image
