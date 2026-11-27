from dataclasses import replace
from unittest.mock import AsyncMock

import pytest

from hawki_backend.auth.models import PrincipalKind
from hawki_backend.url_ingestion.crawler import ExternalCrawlerBoundary
from hawki_workflow_control.temporal_client import TemporalUnavailable

from .conftest import request


@pytest.fixture
def upstream(env):
    env.port.crawler_operation = AsyncMock()
    env.service.crawler = ExternalCrawlerBoundary(env.port)
    env.principal = replace(env.principal, kind=PrincipalKind.SERVICE)
    env.app.state.authenticator.authenticate.return_value = env.principal
    return env.port.crawler_operation


def test_catalog_routes_through_temporal(env, upstream):
    upstream.return_value = {"success": True, "tasks": [{"id": "weekly"}]}
    response = request(env, "/v1/scraper/tasks", method="GET")
    assert response.status_code == 200
    assert response.json() == upstream.return_value
    upstream.assert_awaited_once_with("catalog", None)


def test_catalog_preserves_unavailable_response(env, upstream):
    upstream.side_effect = TemporalUnavailable()
    response = request(env, "/v1/scraper/tasks", method="GET")
    assert response.status_code == 503
    assert response.json() == {"detail": "crawler_catalog_unavailable"}


@pytest.mark.parametrize("auth", ["missing", "scope"])
def test_catalog_remains_service_authenticated_and_requires_ingest(env, upstream, auth):
    headers = {}
    if auth == "scope":
        env.app.state.authenticator.authenticate.return_value = replace(
            env.principal, scopes=frozenset({"rag:query"})
        )
        headers["Authorization"] = "Bearer test"
    response = request(env, "/v1/scraper/tasks", method="GET", headers=headers)
    assert response.status_code == (401 if auth == "missing" else 403)
    upstream.assert_not_awaited()
