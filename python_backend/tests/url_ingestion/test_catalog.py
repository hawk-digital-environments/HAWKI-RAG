from dataclasses import replace

import httpx
import pytest

from hawki_backend.auth.models import PrincipalKind
from hawki_backend.url_ingestion import catalog
from hawki_backend.url_ingestion.crawler import ExternalCrawlerBoundary

from .conftest import request
from .crawler_fixtures import api_schema


@pytest.fixture
def upstream(env, monkeypatch):
    original_client = httpx.Client
    calls = []
    replies = {
        "/ui/api/profiles": (404, {"detail": "Not Found"}),
        "/openapi.json": (200, api_schema()),
        "/health": (200, {"status": "healthy"}),
    }

    def handler(wire):
        calls.append(wire)
        status, value = replies[wire.url.path]
        return httpx.Response(status, json=value)

    def factory(**kwargs):
        return original_client(transport=httpx.MockTransport(handler), **kwargs)

    monkeypatch.setattr(catalog.httpx, "Client", factory)
    monkeypatch.setenv("CUSTOM_CRAWLER_TASK_UI_URL", "http://scraper.test")
    monkeypatch.delenv("CUSTOM_CRAWLER_TASK_UI_PROFILES_PATH", raising=False)
    monkeypatch.delenv("CUSTOM_CRAWLER_TASK_UI_TASKS_PATH", raising=False)
    env.service.crawler = ExternalCrawlerBoundary(
        "http://scraper.test", client_factory=factory
    )
    env.authenticator = env.app.state.authenticator
    env.principal = replace(env.principal, kind=PrincipalKind.SERVICE)
    env.authenticator.authenticate.return_value = env.principal
    return replies, calls


def test_healthy_api_without_optional_catalog_returns_explicit_empty_catalog(
    env, upstream
):
    response = request(env, "/v1/scraper/tasks", method="GET")
    assert response.status_code == 200
    assert response.json()["success"] and response.json()["tasks"] == []
    assert "no saved profile/task catalog" in response.json()["message"]
    assert [str(wire.url.path) for wire in upstream[1]] == [
        "/ui/api/profiles",
        "/openapi.json",
        "/health",
    ]


@pytest.mark.parametrize(
    "failure", ["contract", "unhealthy", "unavailable", "profile_error"]
)
def test_catalog_absence_does_not_hide_crawler_or_contract_failures(
    env, upstream, failure
):
    replies, _ = upstream
    if failure == "contract":
        replies["/openapi.json"] = (200, {})
    elif failure == "unhealthy":
        replies["/health"] = (200, {"status": "unhealthy"})
    elif failure == "unavailable":
        replies["/health"] = (503, {})
    else:
        replies["/ui/api/profiles"] = (500, {})
    response = request(env, "/v1/scraper/tasks", method="GET")
    assert response.status_code == 503
    assert response.json() == {"detail": "crawler_catalog_unavailable"}


@pytest.mark.parametrize("custom", ["origin", "path"])
def test_missing_explicit_external_catalog_is_not_reported_as_empty(
    env, upstream, monkeypatch, custom
):
    if custom == "origin":
        monkeypatch.setenv("CUSTOM_CRAWLER_TASK_UI_URL", "http://separate-ui.test")
    else:
        monkeypatch.setenv("CUSTOM_CRAWLER_TASK_UI_PROFILES_PATH", "/missing-profiles")
        upstream[0]["/missing-profiles"] = (404, {})
    response = request(env, "/v1/scraper/tasks", method="GET")
    assert response.status_code == 503
    assert len(upstream[1]) == 1


def test_real_catalog_still_normalizes_profiles_tasks_and_strips_extra_fields(
    env, upstream
):
    replies, calls = upstream
    replies["/ui/api/profiles"] = (
        200,
        {
            "profiles": [
                {
                    "name": "cedar",
                    "match_hosts": ["example.org"],
                    "profile": {
                        "name": "Cedar",
                        "max_pages": 2,
                        "internal_token": "not-public",
                    },
                    "hostPath": "/server/private/profile.json",
                }
            ]
        },
    )
    replies["/ui/api/tasks"] = (
        200,
        {"tasks": [{"id": "weekly", "profileId": "cedar", "schedule": "0 1 * * 1"}]},
    )
    response = request(env, "/v1/scraper/tasks", method="GET")
    assert response.status_code == 200
    assert {row["id"] for row in response.json()["tasks"]} == {"manual-cedar", "weekly"}
    assert "not-public" not in response.text and "/server/private" not in response.text
    assert len(calls) == 2


@pytest.mark.parametrize("auth", ["missing", "scope"])
def test_catalog_remains_service_authenticated_and_requires_ingest(env, upstream, auth):
    headers = {}
    if auth == "scope":
        env.authenticator.authenticate.return_value = replace(
            env.principal, scopes=frozenset({"rag:query"})
        )
        headers["Authorization"] = "Bearer test"
    response = request(env, "/v1/scraper/tasks", method="GET", headers=headers)
    assert response.status_code == (401 if auth == "missing" else 403)
    assert upstream[1] == []
