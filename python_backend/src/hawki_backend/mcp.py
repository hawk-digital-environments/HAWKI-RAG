"""Streamable HTTP tools served by the existing FastAPI backend."""

import base64
import binascii
from io import BytesIO
from typing import Annotated, Literal

from fastapi import Depends, FastAPI, HTTPException, Request, Response
from mcp.server import MCPServer
from mcp.server.mcpserver import Context
from mcp.server.mcpserver.exceptions import ToolError
from mcp.server.transport_security import TransportSecuritySettings
from pydantic import BaseModel, ConfigDict, Field, JsonValue, ValidationError
from starlette.applications import Starlette
from starlette.concurrency import run_in_threadpool
from starlette.types import ASGIApp, Receive, Scope, Send

from hawki_backend.api.v1.query import QueryRequestBody, execute_query
from hawki_backend.auth.dependencies import get_authenticated_principal
from hawki_backend.auth.models import AuthenticatedPrincipal
from hawki_backend.authorization import (
    AuthorizationAction,
    AuthorizationUnavailableError,
)
from hawki_backend.managed_documents.api import (
    execute_mutation,
    execute_text_mutation,
)
from hawki_backend.managed_documents.input import (
    ManagedError,
    ManagedInput,
    ManagedTextInput,
)
from hawki_backend.text_ingestion.settings import MAX_TEXT_CHARACTERS
from hawki_rag_contracts.retrieval.query import PublicQueryResponse

_MAX_FILE_BYTES = 4 * 1024 * 1024
_MAX_ENCODED_FILE_CHARS = 4 * ((_MAX_FILE_BYTES + 2) // 3)
_MAX_MCP_BODY_BYTES = 8 * 1024 * 1024
_MCP_SCOPES = {
    AuthorizationAction.QUERY.required_scope,
    AuthorizationAction.INGEST.required_scope,
}


class MarkdownFile(BaseModel):
    """A Markdown filename and UTF-8 bytes supplied by the MCP client."""

    model_config = ConfigDict(extra="forbid")
    filename: str = Field(min_length=4, max_length=255)
    content_base64: str = Field(min_length=4, max_length=_MAX_ENCODED_FILE_CHARS)

    def decode(self) -> bytes:
        if (
            not self.filename.lower().endswith(".md")
            or "/" in self.filename
            or "\\" in self.filename
            or not self.filename.isprintable()
            or self.filename != self.filename.strip()
        ):
            raise ToolError("Provide a plain .md filename without a path.")
        try:
            content = base64.b64decode(self.content_base64, validate=True)
            text = content.decode("utf-8")
        except (binascii.Error, UnicodeError, ValueError):
            raise ToolError(
                "The file must contain base64-encoded UTF-8 Markdown."
            ) from None
        if len(content) > _MAX_FILE_BYTES:
            raise ToolError("Markdown files are limited to 4 MiB through MCP.")
        if not text.strip():
            raise ToolError("The Markdown file must contain text.")
        return content


class _McpResponse(Response):
    """Let the MCP SDK write its HTTP response after FastAPI authenticates."""

    def __init__(self, app: ASGIApp) -> None:
        super().__init__()
        self._app = app

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        await self._app(scope, receive, send)


def _tool_principal(
    ctx: Context, action: AuthorizationAction
) -> AuthenticatedPrincipal:
    request = ctx.request_context.request
    principal = (
        getattr(request.state, "mcp_principal", None) if request is not None else None
    )
    if principal is None:
        raise ToolError("The request has no authenticated principal.")
    if not principal.has_scopes(action.required_scope):
        raise ToolError(f"The {action.required_scope} scope is required (HTTP 403).")
    return principal


def mount_mcp(application: FastAPI) -> Starlette:
    """Register the authenticated MCP route and return its lifespan owner."""
    server = MCPServer("HAWKI-RAG")

    @server.tool()
    async def query_search(
        ctx: Context, request: QueryRequestBody
    ) -> PublicQueryResponse:
        """Search an authorized dataset and optionally generate a grounded answer.

        The request object accepts the same options as POST /v1/query.
        Set generate to false to return ranked evidence without an answer.
        """
        principal = _tool_principal(ctx, AuthorizationAction.QUERY)
        try:
            return await run_in_threadpool(
                execute_query, request, principal, application.state
            )
        except HTTPException as error:
            message = (
                error.detail.get("message", "Query failed.")
                if isinstance(error.detail, dict)
                else "Query failed."
            )
            raise ToolError(f"{message} (HTTP {error.status_code})") from None

    @server.tool()
    async def submit_document(
        ctx: Context,
        dataset_id: Annotated[
            str, Field(pattern=r"^[A-Za-z0-9][A-Za-z0-9._:-]{0,159}$")
        ],
        text: Annotated[
            str | None, Field(min_length=1, max_length=MAX_TEXT_CHARACTERS)
        ] = None,
        markdown_file: MarkdownFile | None = None,
        content_format: Literal["plain_text", "markdown"] = "plain_text",
        metadata: dict[str, JsonValue] | None = None,
        idempotency_key: Annotated[str | None, Field(max_length=255)] = None,
    ) -> dict[str, JsonValue]:
        """Submit inline text or a UTF-8 Markdown file to an ingest-granted dataset.

        Provide exactly one of text or markdown_file. Set content_format to
        markdown for inline Markdown. For a file, pass its .md filename and
        base64-encoded bytes. Reuse the idempotency key when retrying. Success
        returns the managed document admission receipt and pipeline IDs.
        """
        principal = _tool_principal(ctx, AuthorizationAction.INGEST)
        instance = application.state.managed_document_service
        if instance is None:
            raise ToolError("Managed document ingestion is unavailable.")
        if (text is None) == (markdown_file is None):
            raise ToolError("Provide exactly one of text or markdown_file.")

        try:
            if markdown_file is None:
                submission = ManagedTextInput(
                    dataset_id=dataset_id,
                    text=text,
                    content_format=content_format,
                    metadata=metadata if metadata is not None else {},
                )
                status, result = await execute_text_mutation(
                    instance,
                    principal,
                    "create",
                    submission,
                    idempotency_key,
                )
            else:
                if content_format != "plain_text":
                    raise ToolError("content_format applies only to inline text.")
                submission = ManagedInput(dataset_id=dataset_id, metadata_json=metadata)
                with BytesIO(markdown_file.decode()) as stream:
                    status, result = await execute_mutation(
                        instance,
                        principal,
                        "create",
                        submission,
                        markdown_file.filename,
                        stream,
                        idempotency_key,
                        None,
                        markdown_file.filename,
                    )
        except ManagedError as error:
            raise ToolError(f"{error.message} (HTTP {error.status})") from None
        except AuthorizationUnavailableError:
            raise ToolError("Managed document authorization is unavailable.") from None
        except ValidationError:
            raise ToolError("The given document data was invalid.") from None
        if status not in {200, 202} or result.get("success") is False:
            raise ToolError(
                f"Submission could not be confirmed (HTTP {status}). "
                "Retry with the same idempotency key."
            )
        return result

    mcp_app = server.streamable_http_app(
        stateless_http=True,
        json_response=True,
        max_request_body_size=_MAX_MCP_BODY_BYTES,
        transport_security=TransportSecuritySettings(
            allowed_hosts=[
                "localhost",
                "localhost:*",
                "127.0.0.1",
                "127.0.0.1:*",
                "rag_backend",
                "rag_backend:*",
            ],
            allowed_origins=["http://localhost:*", "http://127.0.0.1:*"],
        ),
    )

    @application.api_route(
        "/mcp", methods=["GET", "POST", "DELETE"], include_in_schema=False
    )
    async def mcp_transport(
        request: Request,
        principal: Annotated[
            AuthenticatedPrincipal, Depends(get_authenticated_principal)
        ],
    ) -> Response:
        if _MCP_SCOPES.isdisjoint(principal.scopes):
            raise HTTPException(
                status_code=403,
                detail="insufficient_scope",
                headers={"WWW-Authenticate": 'Bearer error="insufficient_scope"'},
            )
        request.state.mcp_principal = principal
        return _McpResponse(mcp_app)

    return mcp_app
