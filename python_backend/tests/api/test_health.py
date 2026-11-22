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


def test_readiness_requires_authentication_and_leaves_health_public():
    async def request():
        async with httpx.AsyncClient(
            transport=httpx.ASGITransport(app=app), base_url="http://test"
        ) as client:
            return await client.get("/v1/readiness"), await client.get("/v1/health")

    ready, health = asyncio.run(request())
    assert ready.status_code in {401, 503}
    assert health.status_code == 200 and health.json() == {"status": "ok"}
