"""Explicit loopback access and HAWKI policy keep downstream credentials private."""

import asyncio

import httpx
import pytest

from hawki_backend.ui_gateway import app, ServiceTokens, _RejectCookies


class Bytes(httpx.AsyncByteStream):
    def __init__(self, data=b'{"ok":true}'):
        self.data = data

    async def __aiter__(self):
        yield self.data


@pytest.fixture
def boundary(tmp_path, monkeypatch):
    token = tmp_path / "access.jwt"
    token.write_text("server-only-service-jwt", encoding="utf-8")
    monkeypatch.setenv("HAWKI_RAG_UI_SERVICE_TOKEN_FILE", str(token))
    monkeypatch.delenv("HAWKI_RAG_UI_TOKEN_URL", raising=False)
    monkeypatch.setenv("HAWKI_RAG_UI_ACCESS_MODE", "hawki")
    app.state.service_tokens = ServiceTokens()
    monkeypatch.setenv("HAWKI_RAG_UI_ORIGIN", "https://rag.test")
    monkeypatch.setenv("HAWKI_RAG_UI_BACKEND_URL", "http://backend.test")
    monkeypatch.setenv(
        "HAWKI_RAG_UI_AUTHORIZATION_URL", "https://hawki.test/rag-management-access"
    )
    return token


def request(
    *,
    gate=204,
    method="GET",
    path="query/datasets",
    headers=None,
    body=None,
    base_url="https://rag.test",
):
    calls = []

    async def handler(wire):
        calls.append(wire)
        if wire.url.host == "hawki.test":
            return httpx.Response(gate)
        return httpx.Response(
            200,
            headers={"content-type": "application/json", "set-cookie": "secret=hidden"},
            stream=Bytes(),
        )

    async def call():
        async with httpx.AsyncClient(
            transport=httpx.MockTransport(handler)
        ) as upstream:
            app.state.http = upstream
            async with httpx.AsyncClient(
                transport=httpx.ASGITransport(app=app), base_url=base_url
            ) as browser:
                return await browser.request(
                    method, "/ui-api/v1/" + path, headers=headers, content=body
                )

    return asyncio.run(call()), calls


@pytest.mark.parametrize("status", [200, 301, 302, 401, 403, 500, 503])
def test_hawki_must_explicitly_authorize_management(boundary, status):
    response, calls = request(gate=status)
    assert response.status_code in {401, 403}
    assert len(calls) == 1
    assert "server-only" not in response.text


def test_gateway_uses_only_the_server_service_token(boundary):
    response, calls = request(
        method="POST",
        path="query?dataset_id=one&dataset_id=two",
        headers={
            "Origin": "https://rag.test",
            "X-Hawki-Browser": "1",
            "Cookie": "hawki_session=browser",
            "Authorization": "Bearer attacker",
            "Idempotency-Key": "operation-1",
            "Content-Type": "application/json",
        },
        body=b'{"dataset_id":"one","query":"hello"}',
    )
    assert response.status_code == 200
    assert calls[0].headers["Cookie"] == "hawki_session=browser"
    assert "authorization" not in calls[0].headers
    assert calls[0].headers["X-Original-Method"] == "POST"
    api = calls[1]
    assert api.url.host == "backend.test"
    assert api.url.path == "/v1/query"
    assert api.headers["Authorization"] == "Bearer server-only-service-jwt"
    assert api.headers["Idempotency-Key"] == "operation-1"
    assert "cookie" not in api.headers
    assert api.url.params.get_list("dataset_id") == ["one", "two"]
    assert api.content == b'{"dataset_id":"one","query":"hello"}'
    assert "set-cookie" not in response.headers
    assert "server-only" not in response.text


@pytest.mark.parametrize(
    "headers",
    [
        {},
        {"Origin": "https://attacker.test", "X-Hawki-Browser": "1"},
        {"Origin": "https://rag.test"},
    ],
)
def test_cross_origin_and_plain_form_mutations_never_contact_backend(boundary, headers):
    response, calls = request(
        method="DELETE", path="documents/example", headers=headers
    )
    assert response.status_code == 403
    assert calls == []


def test_missing_token_file_fails_closed_after_hawki_approval(boundary):
    boundary.unlink()
    response, calls = request()
    assert response.status_code == 503
    assert len(calls) == 1


def test_compressed_api_responses_remain_valid_browser_json(boundary):
    import gzip

    async def handler(wire):
        if wire.url.host == "hawki.test":
            return httpx.Response(204)
        return httpx.Response(
            200,
            headers={"Content-Type": "application/json", "Content-Encoding": "gzip"},
            stream=Bytes(gzip.compress(b'{"ok":true}')),
        )

    async def run():
        async with httpx.AsyncClient(
            transport=httpx.MockTransport(handler)
        ) as upstream:
            app.state.http = upstream
            async with httpx.AsyncClient(
                transport=httpx.ASGITransport(app=app), base_url="https://rag.test"
            ) as browser:
                response = await browser.get("/ui-api/v1/query/datasets")
                assert response.json() == {"ok": True}
                assert "content-encoding" not in response.headers

    asyncio.run(run())


def test_no_human_login_or_session_endpoint_exists(boundary):
    async def call():
        async with httpx.AsyncClient(
            transport=httpx.ASGITransport(app=app), base_url="http://test"
        ) as browser:
            for path in (
                "/login",
                "/logout",
                "/session",
                "/internal/pipeline/worker-events",
            ):
                assert (await browser.post(path)).status_code == 404

    asyncio.run(call())


def test_crawler_ui_reads_use_hawki_gate_and_mutations_are_refused(
    boundary, monkeypatch
):
    monkeypatch.setenv("CUSTOM_CRAWLER_TASK_UI_URL", "http://crawler.test")
    monkeypatch.delenv("EXTERNAL_SCRAPER_TOKEN", raising=False)
    monkeypatch.delenv("CUSTOM_CRAWLER_API_KEY", raising=False)
    calls = []

    async def handler(wire):
        calls.append(wire)
        if wire.url.host == "hawki.test":
            return httpx.Response(204)
        return httpx.Response(
            200,
            headers={"Content-Type": "text/html"},
            stream=Bytes(b"<html>Crawler UI</html>"),
        )

    async def run():
        async with httpx.AsyncClient(
            transport=httpx.MockTransport(handler)
        ) as upstream:
            app.state.http = upstream
            async with httpx.AsyncClient(
                transport=httpx.ASGITransport(app=app), base_url="https://rag.test"
            ) as browser:
                response = await browser.get(
                    "/ui/tasks/new", headers={"Cookie": "hawki_session=browser"}
                )
                assert response.status_code == 200
                assert response.text == "<html>Crawler UI</html>"
                assert calls[0].url.host == "hawki.test"
                assert calls[1].url == "http://crawler.test/ui/tasks/new"
                assert "authorization" not in calls[1].headers
                assert "cookie" not in calls[1].headers
                assert (await browser.post("/ui/api/crawler/submit")).status_code == 409
                assert len(calls) == 2

    asyncio.run(run())


def test_outbound_responses_cannot_create_shared_human_sessions():
    from http.cookiejar import CookieJar

    seen = []

    async def handler(wire):
        seen.append(wire)
        return httpx.Response(
            204, headers={"Set-Cookie": "human_session=never-store; Path=/"}
        )

    async def run():
        async with httpx.AsyncClient(
            transport=httpx.MockTransport(handler),
            cookies=CookieJar(policy=_RejectCookies()),
        ) as client:
            await client.get("https://hawki.test/authorize")
            await client.get("https://hawki.test/authorize")
            assert not list(client.cookies.jar)
            assert "Cookie" not in seen[1].headers

    asyncio.run(run())


def test_oauth_service_credentials_are_exchanged_only_on_the_server(
    boundary, monkeypatch
):
    secret = boundary.with_name("client.secret")
    secret.write_text("issuer-client-secret", encoding="utf-8")
    monkeypatch.setenv("HAWKI_RAG_UI_TOKEN_URL", "https://issuer.test/token")
    monkeypatch.setenv("HAWKI_RAG_UI_CLIENT_ID", "rag-management")
    monkeypatch.setenv("HAWKI_RAG_UI_CLIENT_SECRET_FILE", str(secret))
    calls = []

    async def handler(wire):
        calls.append(wire)
        return httpx.Response(
            200,
            json={
                "access_token": "issuer-service-jwt",
                "token_type": "Bearer",
                "expires_in": 300,
            },
        )

    async def run():
        provider = ServiceTokens()
        async with httpx.AsyncClient(transport=httpx.MockTransport(handler)) as client:
            assert await provider.get(client) == "issuer-service-jwt"
            assert await provider.get(client) == "issuer-service-jwt"
            assert len(calls) == 1
            provider.invalidate()
            assert await provider.get(client) == "issuer-service-jwt"
            assert len(calls) == 2
        assert calls[0].url == "https://issuer.test/token"
        assert calls[0].headers["Authorization"].startswith("Basic ")
        assert b"grant_type=client_credentials" in calls[0].content
        assert "issuer-client-secret" not in str(calls[0].url)

    asyncio.run(run())


def test_browser_basic_is_never_forwarded_even_with_retired_flag(boundary, monkeypatch):
    monkeypatch.setenv("HAWKI_RAG_UI_FORWARD_BASIC_AUTH", "true")
    response, calls = request(headers={"Authorization": "Basic bG9jYWw6dGVzdA=="})
    assert response.status_code == 200
    assert "authorization" not in calls[0].headers
    assert calls[1].headers["Authorization"] == "Bearer server-only-service-jwt"
    response, calls = request(headers={"Authorization": "Bearer browser-supplied"})
    assert response.status_code == 200
    assert "authorization" not in calls[0].headers


@pytest.fixture
def local_boundary(boundary, monkeypatch):
    monkeypatch.setenv("HAWKI_RAG_UI_ACCESS_MODE", "local")
    monkeypatch.setenv("HAWKI_RAG_UI_ORIGIN", "http://localhost:8080")
    monkeypatch.delenv("HAWKI_RAG_UI_AUTHORIZATION_URL", raising=False)
    return boundary


@pytest.mark.parametrize("path", ["ops/stats", "ops/health", "query/datasets"])
def test_loopback_ui_needs_no_browser_credentials_or_human_gate(local_boundary, path):
    response, calls = request(path=path, base_url="http://localhost:8080")
    assert response.status_code == 200
    assert len(calls) == 1 and calls[0].url.host == "backend.test"
    assert calls[0].headers["authorization"] == "Bearer server-only-service-jwt"
    assert "cookie" not in calls[0].headers
    assert "www-authenticate" not in response.headers
    assert "set-cookie" not in response.headers
    assert "server-only" not in response.text


def test_local_mutations_use_origin_marker_and_only_server_bearer(local_boundary):
    response, calls = request(
        method="POST",
        path="query",
        base_url="http://localhost:8080",
        headers={
            "Origin": "http://localhost:8080",
            "X-Hawki-Browser": "1",
            "Authorization": "Basic browser-input",
            "Cookie": "browser=untrusted",
        },
        body=b"{}",
    )
    assert response.status_code == 200 and len(calls) == 1
    assert calls[0].headers["authorization"] == "Bearer server-only-service-jwt"
    assert "cookie" not in calls[0].headers


def test_local_multipart_upload_preserves_binary_bytes_boundary_and_length(
    local_boundary,
):
    boundary = "rawki-binary-upload-boundary"
    body = (
        (
            f'--{boundary}\r\nContent-Disposition: form-data; name="dataset_id"\r\n\r\nfiles\r\n'
            f'--{boundary}\r\nContent-Disposition: form-data; name="graph"\r\n\r\ntrue\r\n'
            f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="fixture.bin"\r\n'
            "Content-Type: application/octet-stream\r\n\r\n"
        ).encode()
        + bytes(range(256)) * 512
        + f"\r\n--{boundary}--\r\n".encode()
    )
    content_type = f"multipart/form-data; boundary={boundary}"
    response, calls = request(
        method="POST",
        path="documents",
        base_url="http://localhost:8080",
        headers={
            "Origin": "http://localhost:8080",
            "X-Hawki-Browser": "1",
            "Content-Type": content_type,
            "Idempotency-Key": "upload-one",
        },
        body=body,
    )
    assert response.status_code == 200 and len(calls) == 1
    assert calls[0].url.path == "/v1/documents"
    assert calls[0].content == body
    assert calls[0].headers["content-type"] == content_type
    assert int(calls[0].headers["content-length"]) == len(body)
    assert calls[0].headers["idempotency-key"] == "upload-one"
    assert calls[0].headers["authorization"] == "Bearer server-only-service-jwt"


@pytest.mark.parametrize(
    "method,headers",
    [
        ("GET", {"Origin": "https://attacker.test"}),
        ("GET", {"Sec-Fetch-Site": "cross-site"}),
        ("POST", {}),
        ("DELETE", {"Origin": "http://localhost:8080"}),
    ],
)
def test_local_cross_origin_and_unmarked_mutations_fail_before_service_call(
    local_boundary, method, headers
):
    response, calls = request(
        method=method, headers=headers, base_url="http://localhost:8080"
    )
    assert response.status_code == 403 and calls == []


@pytest.mark.parametrize(
    "origin,host,status",
    [
        ("https://rag.test", "http://localhost:8080", 503),
        ("http://localhost:8080", "https://attacker.test", 403),
        ("http://localhost:0", "http://localhost:8080", 503),
    ],
)
def test_local_mode_cannot_be_enabled_for_nonloopback_or_invalid_origins(
    local_boundary, monkeypatch, origin, host, status
):
    monkeypatch.setenv("HAWKI_RAG_UI_ORIGIN", origin)
    response, calls = request(base_url=host)
    assert response.status_code == status and calls == []


def test_local_ui_fails_closed_when_its_service_credential_is_missing(local_boundary):
    local_boundary.unlink()
    response, calls = request(base_url="http://localhost:8080")
    assert response.status_code == 503 and calls == []


def test_unknown_ui_access_mode_never_calls_issuer_or_backend(boundary, monkeypatch):
    monkeypatch.setenv("HAWKI_RAG_UI_ACCESS_MODE", "anonymous")
    response, calls = request()
    assert response.status_code == 503 and calls == []
