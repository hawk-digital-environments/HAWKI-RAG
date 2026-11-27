import asyncio
import base64
from dataclasses import replace
from pathlib import Path
from unittest.mock import Mock

from httpx2 import ASGITransport, AsyncClient
from mcp import Client
from mcp.client.streamable_http import streamable_http_client
import pytest

from hawki_backend.api.v1 import query as query_api
from hawki_backend.auth.models import PrincipalKind
from hawki_backend.persistence.schema import dataset_grants
from hawki_rag_contracts.retrieval.query import QueryResponse

from .conftest import send
from .test_lifecycle import revision

_ENDPOINT = "http://rag_backend/mcp"
_BEARER = {"Authorization": "Bearer test"}


def call(env, arguments, tool="submit_document"):
    async def invoke():
        async with env.app.router.lifespan_context(env.app):
            async with AsyncClient(
                transport=ASGITransport(app=env.app), headers=_BEARER
            ) as http:
                async with Client(
                    streamable_http_client(_ENDPOINT, http_client=http)
                ) as client:
                    tools = await client.list_tools()
                    assert {tool.name for tool in tools.tools} == {
                        "submit_document",
                        "query_search",
                    }
                    assert all(
                        "ctx" not in item.input_schema["properties"]
                        for item in tools.tools
                    )
                    return await client.call_tool(tool, arguments)

    return asyncio.run(invoke())


@pytest.mark.parametrize("kind", ["text", "file"])
def test_mcp_and_rest_share_managed_admission_and_idempotency(env, kind):
    markdown = "# Café\n\nLocal Markdown.".encode()
    metadata = {"course": "design", "year": 2026}
    args = {
        "dataset_id": "files",
        "idempotency_key": "same-ingestion",
        "metadata": metadata,
    }
    if kind == "text":
        args.update(text=markdown.decode(), content_format="markdown")
        rest = {
            "json": {
                key: value for key, value in args.items() if key != "idempotency_key"
            }
        }
    else:
        args["markdown_file"] = {
            "filename": "notes.md",
            "content_base64": base64.b64encode(markdown).decode(),
        }
        rest = {
            "data": {
                "dataset_id": "files",
                "metadata_json": '{"course":"design","year":2026}',
            },
            "files": {"file": ("notes.md", markdown, "text/markdown")},
        }

    result = call(env, args)
    assert not result.is_error
    receipt = result.structured_content
    replay = send(env, key="same-ingestion", **rest)
    assert replay.status_code == 202
    replay_receipt = replay.json()
    # Reconciliation may refresh updated_at without dispatching a new workflow.
    assert replay_receipt["document"].pop("updated_at") >= receipt["document"].pop(
        "updated_at"
    )
    assert replay_receipt == receipt
    assert env.port.start_ingest_workflow.await_count == 1
    row = revision(env, receipt)
    assert row["workflow_id"].startswith("ingest-source-")
    wire = row["workflow_input"]
    assert wire["managed_upload_protocol"] == 2
    assert Path(wire["upload"]["local_path"]).read_bytes() == markdown
    assert wire.get("upload_metadata") == (metadata if kind == "text" else None)


@pytest.mark.parametrize(
    ("args", "expected"),
    [
        ({}, "exactly one"),
        ({"text": " ", "idempotency_key": "key"}, "invalid"),
        (
            {"markdown_file": {"filename": "../notes.md", "content_base64": "IyBh"}},
            "without a path",
        ),
        (
            {"markdown_file": {"filename": "notes.md", "content_base64": "%%%!"}},
            "base64-encoded UTF-8",
        ),
        (
            {"dataset_id": "ungranted", "text": "secret"},
            "HTTP 404",
        ),
    ],
)
def test_mcp_rejects_invalid_and_ungranted_submissions(env, args, expected):
    result = call(env, {"dataset_id": "files", **args})
    assert result.is_error
    assert expected in result.content[0].text
    assert env.port.start_ingest_workflow.await_count == 0


@pytest.mark.parametrize(("auth", "status"), [(False, 401), (True, 403)])
def test_fastapi_authenticates_the_mcp_transport_before_tool_dispatch(
    env, auth, status
):
    env.authenticator.authenticate.return_value = replace(
        env.principal, scopes={"rag:datasets:manage"}
    )

    async def invoke():
        async with AsyncClient(transport=ASGITransport(app=env.app)) as client:
            return await client.post(_ENDPOINT, headers=_BEARER if auth else {})

    response = asyncio.run(invoke())
    assert response.status_code == status
    assert "Bearer" in response.headers["WWW-Authenticate"]
    assert env.port.start_ingest_workflow.await_count == 0


@pytest.fixture
def query_client(env, monkeypatch):
    principal = replace(
        env.principal,
        kind=PrincipalKind.SERVICE,
        client_id="hawki-service",
        scopes={"rag:query"},
    )
    env.authenticator.authenticate.return_value = principal
    monkeypatch.setenv("RAG_BACKEND_SERVICE_ONLY", "true")
    env.app.state.grant_policy = env.service.policy
    env.app.state.query_settings = env.service.query_settings
    env.app.state.query_dependencies = object()
    retrieval = Mock(
        return_value=QueryResponse(
            ok=True,
            count=1,
            hits=[
                {
                    "id": "hit",
                    "score": 0.8,
                    "payload": {"content": "Evidence", "qdrant_collection": "private"},
                }
            ],
        )
    )
    monkeypatch.setattr(query_api, "execute_authorized_query", retrieval)
    return principal, retrieval


def test_query_only_token_gets_the_same_result_as_rest(env, query_client):
    principal, retrieval = query_client
    with env.engine.begin() as connection:
        connection.execute(
            dataset_grants.insert().values(
                tenant_id=principal.tenant_id,
                dataset_id="files",
                principal_type=principal.kind.value,
                principal_id=principal.principal_id,
                permission="query",
            )
        )
    body = {
        "dataset_id": "files",
        "query": "interaction design",
        "generate": False,
        "top_k": 3,
        "metadata_filters": {"field": "course", "op": "eq", "value": "design"},
    }
    result = call(env, {"request": body}, "query_search")
    assert not result.is_error
    rest = send(env, path="/v1/query", json=body)
    assert rest.status_code == 200
    assert result.structured_content == rest.json()
    assert result.structured_content["hits"][0]["payload"] == {"content": "Evidence"}
    scoped = retrieval.call_args.args[0]
    assert scoped.authorized_scope.qdrant_collection == "historic_vectors"
    assert scoped.metadata_filters is not None
    assert scoped.top_k == 3 and not scoped.generate


def test_query_scope_without_dataset_grant_cannot_search(env, query_client):
    _, retrieval = query_client
    result = call(
        env, {"request": {"dataset_id": "files", "query": "hello"}}, "query_search"
    )
    assert result.is_error
    assert "HTTP 404" in result.content[0].text
    retrieval.assert_not_called()


@pytest.mark.parametrize(
    ("scope", "tool", "arguments", "missing_scope"),
    [
        (
            "rag:query",
            "submit_document",
            {"dataset_id": "files", "text": "hello"},
            "rag:ingest",
        ),
        (
            "rag:ingest",
            "query_search",
            {"request": {"dataset_id": "files", "query": "hello"}},
            "rag:query",
        ),
    ],
)
def test_mcp_scopes_do_not_grant_access_to_the_other_tool(
    env, query_client, scope, tool, arguments, missing_scope
):
    principal, retrieval = query_client
    env.authenticator.authenticate.return_value = replace(principal, scopes={scope})
    result = call(env, arguments, tool)
    assert result.is_error and missing_scope in result.content[0].text
    assert env.port.start_ingest_workflow.await_count == 0
    retrieval.assert_not_called()


def test_legacy_streamable_http_tools_call(env):
    async def invoke():
        async with env.app.router.lifespan_context(env.app):
            async with AsyncClient(transport=ASGITransport(app=env.app)) as client:
                response = await client.post(
                    _ENDPOINT,
                    headers={
                        **_BEARER,
                        "Accept": "application/json, text/event-stream",
                        "MCP-Protocol-Version": "2025-11-25",
                    },
                    json={
                        "jsonrpc": "2.0",
                        "id": 1,
                        "method": "tools/call",
                        "params": {
                            "name": "submit_document",
                            "arguments": {
                                "dataset_id": "files",
                                "text": "Legacy client",
                            },
                        },
                    },
                )
        return response.raise_for_status().json()

    result = asyncio.run(invoke())["result"]
    assert result["structuredContent"]["pipeline"]["task_id"]
    assert env.port.start_ingest_workflow.await_count == 1
