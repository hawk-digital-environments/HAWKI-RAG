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


def test_health_is_the_only_endpoint() -> None:
    paths = app.openapi()["paths"]

    assert set(paths) == {"/v1/health"}
    assert set(paths["/v1/health"]) == {"get"}
