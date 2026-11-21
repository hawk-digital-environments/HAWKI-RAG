"""Server-side service transport for HAWKI or an explicit loopback-only UI.

This process holds one deployment-owned, tenant-scoped service access token.
It does not log in users, mint JWTs, create sessions, or resolve human identities.
The default deployment requires HAWKI's external management approval. The local
profile trusts its loopback ingress, without human credentials or a login flow.
"""

from collections.abc import AsyncIterator
from contextlib import asynccontextmanager
from http.cookiejar import CookieJar, DefaultCookiePolicy
import asyncio
import os
import ssl
from pathlib import Path
import time
from urllib.parse import urlsplit

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import StreamingResponse
from starlette.background import BackgroundTask
from starlette.concurrency import run_in_threadpool
import httpx

MAX_BODY_BYTES = 103 * 1024 * 1024


class _RejectCookies(DefaultCookiePolicy):
    """Outbound responses must not create shared human sessions in this process."""

    def set_ok(self, cookie, request):
        return False

    def return_ok(self, cookie, request):
        return False


def configured_url(name: str, *, origin: bool = False) -> str:
    value = os.environ.get(name, "").strip()
    try:
        parts = urlsplit(value)
        valid = (
            parts.scheme in {"http", "https"}
            and parts.hostname
            and parts.port != 0
            and not parts.username
            and not parts.password
            and not parts.query
            and not parts.fragment
            and (not origin or not parts.path)
        )
    except ValueError:
        valid = False
    if not valid:
        raise HTTPException(503, "ui_boundary_unavailable")
    return value.rstrip("/")


def service_token() -> str:
    """Read every request so an external issuer can rotate expiring tokens."""
    filename = os.environ.get("HAWKI_RAG_UI_SERVICE_TOKEN_FILE", "")
    try:
        with Path(filename).open(encoding="utf-8") as stream:
            token = stream.read(16385).strip()
        if not token or len(token) > 16384 or any(char.isspace() for char in token):
            raise ValueError()
        return token
    except (OSError, ValueError):
        raise HTTPException(503, "ui_boundary_unavailable") from None


class ServiceTokens:
    """Obtain short-lived service access tokens from the existing OAuth issuer."""

    def __init__(self) -> None:
        self.lock = asyncio.Lock()
        self.token: str | None = None
        self.refresh_at = 0.0

    async def get(self, client: httpx.AsyncClient) -> str:
        if not os.environ.get("HAWKI_RAG_UI_TOKEN_URL"):
            return await run_in_threadpool(service_token)
        async with self.lock:
            if self.token is not None and time.monotonic() < self.refresh_at:
                return self.token
            url = configured_url("HAWKI_RAG_UI_TOKEN_URL")
            if not url.startswith("https://"):
                raise HTTPException(503, "ui_boundary_unavailable")
            client_id = os.environ.get("HAWKI_RAG_UI_CLIENT_ID", "")
            try:
                secret = await run_in_threadpool(
                    Path(
                        os.environ.get("HAWKI_RAG_UI_CLIENT_SECRET_FILE", "")
                    ).read_text,
                    encoding="utf-8",
                )
                secret = secret.strip()
                if not client_id or not secret or len(secret) > 8192:
                    raise ValueError()
                response = await client.post(
                    url,
                    data={
                        "grant_type": "client_credentials",
                        "scope": os.environ.get(
                            "HAWKI_RAG_UI_SCOPES",
                            "rag:query rag:ingest rag:datasets:manage",
                        ),
                    },
                    auth=httpx.BasicAuth(client_id, secret),
                    headers={"Accept": "application/json"},
                    timeout=5,
                )
                response.raise_for_status()
                if len(response.content) > 64 * 1024:
                    raise ValueError()
                value = response.json()
                token = value["access_token"]
                ttl = value["expires_in"]
                if (
                    not isinstance(token, str)
                    or not token
                    or len(token) > 16384
                    or any(char.isspace() for char in token)
                    or value.get("token_type", "").lower() != "bearer"
                    or type(ttl) is not int
                    or ttl < 30
                ):
                    raise ValueError()
            except (
                OSError,
                httpx.HTTPError,
                ValueError,
                KeyError,
                TypeError,
                AttributeError,
            ):
                raise HTTPException(503, "ui_service_auth_unavailable") from None
            self.token = token
            self.refresh_at = time.monotonic() + min(ttl, 3600) - 30
            return token

    def invalidate(self) -> None:
        self.token = None
        self.refresh_at = 0.0


@asynccontextmanager
async def lifespan(application: FastAPI) -> AsyncIterator[None]:
    async with httpx.AsyncClient(
        timeout=httpx.Timeout(180, connect=5),
        follow_redirects=False,
        trust_env=False,
        cookies=CookieJar(policy=_RejectCookies()),
        verify=ssl.create_default_context(cafile=os.environ.get("SSL_CERT_FILE")),
    ) as client:
        application.state.http = client
        application.state.service_tokens = ServiceTokens()
        yield


app = FastAPI(docs_url=None, redoc_url=None, openapi_url=None, lifespan=lifespan)


@app.get("/health")
def health():
    return {"status": "ok"}


async def authorize_management(request: Request) -> httpx.AsyncClient:
    origin = configured_url("HAWKI_RAG_UI_ORIGIN", origin=True)
    if request.method not in {"GET", "HEAD"} and (
        request.headers.get("origin") != origin
        or request.headers.get("x-hawki-browser") != "1"
    ):
        raise HTTPException(403, "ui_origin_refused")
    client = request.app.state.http
    mode = os.environ.get("HAWKI_RAG_UI_ACCESS_MODE", "hawki")
    if mode == "local":
        # This mode is explicitly selected by the loopback-bound local overlay.
        # Neither browser credentials nor forwarded headers establish trust.
        loopback = {"localhost", "127.0.0.1", "::1"}
        if urlsplit(origin).hostname not in loopback:
            raise HTTPException(503, "ui_boundary_unavailable")
        if (
            request.url.hostname not in loopback
            or request.headers.get("origin", origin) != origin
            or request.headers.get("sec-fetch-site") == "cross-site"
        ):
            raise HTTPException(403, "ui_origin_refused")
        return client
    if mode != "hawki":
        raise HTTPException(503, "ui_boundary_unavailable")
    authorize = configured_url("HAWKI_RAG_UI_AUTHORIZATION_URL")
    headers = {
        "Cookie": request.headers.get("cookie", ""),
        "X-Original-Method": request.method,
        "X-Original-URI": request.url.path
        + ("?" + request.url.query if request.url.query else ""),
    }
    try:
        gate = await client.get(
            authorize,
            headers=headers,
            timeout=5,
        )
    except httpx.HTTPError:
        raise HTTPException(503, "ui_authorization_unavailable") from None
    if gate.status_code != 204:
        raise HTTPException(
            401 if gate.status_code == 401 else 403,
            "ui_management_access_required",
        )
    return client


@app.api_route(
    "/ui-api/v1/{path:path}", methods=["GET", "HEAD", "POST", "PUT", "DELETE"]
)
async def proxy(path: str, request: Request):
    backend = configured_url("HAWKI_RAG_UI_BACKEND_URL")
    # Do not permit an absolute/encoded target, traversal, callbacks or a second
    # API prefix. httpx must only ever contact the configured public API origin.
    if (
        not path
        or any(part in {"", ".", ".."} for part in path.split("/"))
        or any(char in path for char in ("\\", "%", "?", "#"))
    ):
        raise HTTPException(404, "ui_route_not_found")
    client = await authorize_management(request)
    token = await request.app.state.service_tokens.get(client)
    body = bytearray()
    async for chunk in request.stream():
        body.extend(chunk)
        if len(body) > MAX_BODY_BYTES:
            raise HTTPException(413, "request_too_large")
    headers = {
        "Authorization": "Bearer " + token,
        "Accept": request.headers.get("accept", "application/json"),
    }
    for name in ("Content-Type", "Idempotency-Key"):
        if name in request.headers:
            headers[name] = request.headers[name]
    try:
        upstream = await client.send(
            client.build_request(
                request.method,
                backend + "/v1/" + path,
                params=request.query_params.multi_items(),
                headers=headers,
                content=bytes(body),
            ),
            stream=True,
        )
    except httpx.HTTPError:
        raise HTTPException(503, "rag_api_unavailable") from None
    if upstream.status_code == 401:
        request.app.state.service_tokens.invalidate()
    response_headers = {
        "Cache-Control": "no-store",
        "X-Content-Type-Options": "nosniff",
    }
    for name in ("Content-Type", "Content-Disposition"):
        if name in upstream.headers:
            response_headers[name] = upstream.headers[name]
    return StreamingResponse(
        upstream.aiter_bytes(),
        status_code=upstream.status_code,
        headers=response_headers,
        background=BackgroundTask(upstream.aclose),
    )


@app.api_route(
    "/ui/{path:path}",
    methods=["GET", "HEAD", "POST", "PUT", "DELETE", "PATCH", "OPTIONS"],
)
async def crawler_ui(path: str, request: Request):
    """Retain the external crawler UI transport, without a second write path."""
    if request.method not in {"GET", "HEAD"}:
        raise HTTPException(409, "use_python_pipeline_operations")
    if any(part in {".", ".."} for part in path.split("/")) or any(
        char in path for char in ("\\", "%", "?", "#")
    ):
        raise HTTPException(404, "ui_route_not_found")
    client = await authorize_management(request)
    variable = next(
        (
            name
            for name in (
                "CUSTOM_CRAWLER_TASK_UI_URL",
                "CUSTOM_CRAWLER_UI_URL",
                "EXTERNAL_SCRAPER_URL",
                "CUSTOM_CRAWLER_URL",
            )
            if os.environ.get(name)
        ),
        None,
    )
    if variable is None:
        raise HTTPException(503, "crawler_ui_unavailable")
    base = configured_url(variable)
    headers = {"Accept": request.headers.get("accept", "text/html")}
    token = os.environ.get(
        "EXTERNAL_SCRAPER_TOKEN", os.environ.get("CUSTOM_CRAWLER_API_KEY", "")
    )
    if token:
        headers["Authorization"] = "Bearer " + token
    try:
        upstream = await client.send(
            client.build_request(
                request.method,
                base + "/ui/" + path,
                params=request.query_params.multi_items(),
                headers=headers,
            ),
            stream=True,
        )
    except httpx.HTTPError:
        raise HTTPException(503, "crawler_ui_unavailable") from None
    return StreamingResponse(
        upstream.aiter_bytes(),
        status_code=upstream.status_code,
        headers={
            "Content-Type": upstream.headers.get("Content-Type", "text/plain"),
            "Cache-Control": "no-store",
            "X-Content-Type-Options": "nosniff",
        },
        background=BackgroundTask(upstream.aclose),
    )
