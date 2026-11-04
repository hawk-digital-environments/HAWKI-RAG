"""Tests for the backend's initial HTTP surface."""

import asyncio

import httpx

from hawki_backend.main import app


def test_health_returns_ok() -> None:
    async def request_health() -> httpx.Response:
        transport = httpx.ASGITransport(app=app)
        async with httpx.AsyncClient(
            transport=transport, base_url="http://testserver"
        ) as client:
            return await client.get("/v1/health")

    response = asyncio.run(request_health())

    assert response.status_code == 200
    assert response.headers["content-type"] == "application/json"
    assert response.json() == {"status": "ok"}


def test_health_and_query_are_the_only_endpoints() -> None:
    paths = app.openapi()["paths"]

    assert set(paths) == {
        "/v1/health",
        "/v1/query",
        "/v1/text-ingestions",
        "/v1/text-ingestions/{source_id}",
    }
    assert set(paths["/v1/health"]) == {"get"}
    assert set(paths["/v1/query"]) == {"post"}
    assert set(paths["/v1/text-ingestions"]) == {"post"}
    assert set(paths["/v1/text-ingestions/{source_id}"]) == {"delete"}
