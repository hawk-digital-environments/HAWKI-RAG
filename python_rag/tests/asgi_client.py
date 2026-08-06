"""Small synchronous facade over httpx's same-thread ASGI transport."""

from __future__ import annotations

import asyncio
from contextlib import asynccontextmanager
from typing import Any
from unittest.mock import patch

import httpx


class ASGITestClient:
    """Exercise an ASGI app without Starlette's cross-thread portal."""

    __test__ = False

    def __init__(self, app: Any, *, raise_server_exceptions: bool = True) -> None:
        self.app = app
        self.raise_server_exceptions = raise_server_exceptions

    def __enter__(self) -> ASGITestClient:
        return self

    def __exit__(self, *_args: object) -> None:
        return None

    def get(self, path: str, **kwargs: Any) -> httpx.Response:
        return self.request("GET", path, **kwargs)

    def post(self, path: str, **kwargs: Any) -> httpx.Response:
        return self.request("POST", path, **kwargs)

    def request(self, method: str, path: str, **kwargs: Any) -> httpx.Response:
        return asyncio.run(self._request(method, path, **kwargs))

    async def _request(self, method: str, path: str, **kwargs: Any) -> httpx.Response:
        lifespan = getattr(getattr(self.app, "router", None), "lifespan_context", None)

        @asynccontextmanager
        async def no_lifespan(_app: Any):
            yield

        lifespan_context = lifespan or no_lifespan
        transport = httpx.ASGITransport(
            app=self.app,
            raise_app_exceptions=self.raise_server_exceptions,
        )

        async def run_sync_endpoint_inline(
            function: Any, *args: Any, **call_kwargs: Any
        ) -> Any:
            # The execution sandbox cannot wake AnyIO's worker-thread portal.
            # Production Uvicorn still uses FastAPI's normal threadpool path.
            return function(*args, **call_kwargs)

        async def run_anyio_sync_inline(
            function: Any,
            *args: Any,
            **_thread_options: Any,
        ) -> Any:
            return function(*args)

        async with lifespan_context(self.app):
            with (
                patch("anyio.to_thread.run_sync", run_anyio_sync_inline),
                patch("fastapi.routing.run_in_threadpool", run_sync_endpoint_inline),
            ):
                async with httpx.AsyncClient(
                    transport=transport,
                    base_url="http://testserver",
                ) as client:
                    return await client.request(method, path, **kwargs)


__all__ = ["ASGITestClient"]
