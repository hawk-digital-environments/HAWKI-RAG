"""Real local persistence and workflow control; only network boundaries are fake."""

import asyncio
import json
from typing import Annotated
from datetime import datetime, timezone
from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock

import httpx
import pytest
import sqlalchemy as sa
from fastapi import Depends, Request
from fastapi.responses import JSONResponse
from pydantic import ValidationError
from sqlalchemy.pool import StaticPool

from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind
from hawki_backend.authorization.policy import LocalGrantPolicy
from hawki_backend.main import create_app
from hawki_backend.persistence.grants import DatabaseGrantReader
from hawki_backend.persistence.schema import datasets, dataset_grants
from hawki_backend.query.settings import DatasetScope, ProviderModels, QuerySettings
from hawki_backend.text_ingestion import errors
from hawki_backend.text_ingestion.api import (
    _INGEST_PRINCIPAL,
    _idempotency_key,
    _map_ingest_error,
    _service,
)
from hawki_backend.text_ingestion.validation import TextIngestionRequest
from hawki_backend.text_ingestion.artifacts import TextArtifactStorage
from hawki_backend.text_ingestion.repository import TextOperationRepository
from hawki_backend.text_ingestion.schema import (
    ingest_text_operations,
    ingest_text_events,
)
from hawki_backend.text_ingestion.service import TextIngestionService
from hawki_backend.text_ingestion.settings import (
    MAX_REQUEST_BYTES,
    TextIngestionSettings,
)
from hawki_backend.text_ingestion.writers import WriterEvent
from hawki_backend.workflow_control.repository import OperationRepository
from hawki_backend.workflow_control.schema import operations
from hawki_backend.workflow_control.service import WorkflowControl
from hawki_workflow_control.temporal_client import TemporalExecution


# Simulate submissions from the previous release to test historical rows and callbacks.
async def _raw_body(request: Request) -> bytes:
    """Read the exact request bytes and enforce the inclusive public limit."""
    body = bytearray()
    async for chunk in request.stream():
        body.extend(chunk)
        if len(body) > MAX_REQUEST_BYTES:
            raise errors.request_too_large()
    return bytes(body)


def _parse_body(raw: bytes) -> TextIngestionRequest:
    try:
        payload = json.loads(raw)
    except (ValueError, RecursionError):
        raise errors.invalid_request() from None
    if not isinstance(payload, dict):
        raise errors.invalid_request()
    try:
        return TextIngestionRequest.model_validate(payload)
    except ValidationError as error:
        for issue in error.errors(include_input=False, include_url=False):
            if issue["loc"] == ("metadata",) and issue["type"] == "value_error":
                raise errors.invalid_request(str(issue["ctx"]["error"])) from None
        raise errors.invalid_request() from None
    except RecursionError:
        raise errors.invalid_request() from None


async def ingest_text(
    raw: Annotated[bytes, Depends(_raw_body)],
    principal: Annotated[AuthenticatedPrincipal, Depends(_INGEST_PRINCIPAL)],
    request: Request,
) -> JSONResponse:
    """Validate, authorize and dispatch one direct-text operation."""
    service = _service(request)
    body = _parse_body(raw)
    key = _idempotency_key(request)
    try:
        outcome = await service.ingest(principal, body, key)
    except Exception as error:  # noqa: BLE001 - mapped to the stable contract
        _map_ingest_error(error)
        raise
    return JSONResponse(
        status_code=200 if outcome.replayed else 202,
        content=outcome.to_payload(),
    )


def payload(**changes):
    return dict(
        external_document_id="doc-1",
        dataset_id="dataset-a",
        text="Hello ä 🌍\n",
        content_format="plain_text",
        **changes,
    )


def send(
    env,
    *,
    body=None,
    key="key-1",
    method="POST",
    path="/v1/text-ingestions",
    auth=True,
    **kwargs,
):
    headers = kwargs.pop("headers", {})
    if auth:
        headers["Authorization"] = "Bearer test-token"
    if key is not None:
        headers["Idempotency-Key"] = key.encode("utf-8")
    if method == "POST" and "content" not in kwargs:
        kwargs["json"] = payload() if body is None else body

    async def call():
        async with httpx.AsyncClient(
            transport=httpx.ASGITransport(app=env.app), base_url="http://test"
        ) as client:
            return await client.request(method, path, headers=headers, **kwargs)

    return asyncio.run(call())


@pytest.fixture
def env(tmp_path, monkeypatch):
    for key in tuple(__import__("os").environ):
        if key.startswith(("RAG_BACKEND_", "RAG_WORKFLOW_", "TEMPORAL_")):
            monkeypatch.delenv(key)
    engine = sa.create_engine(
        "sqlite://",
        poolclass=StaticPool,
        connect_args={"check_same_thread": False},
        execution_options={"schema_translate_map": {"hawki_backend": None}},
    )
    for table in (
        datasets,
        dataset_grants,
        operations,
        ingest_text_operations,
        ingest_text_events,
    ):
        table.create(engine)
    with engine.begin() as conn:
        conn.execute(
            datasets.insert().values(
                tenant_id="default",
                dataset_id="dataset-a",
                status="active",
                name="Dataset A",
                qdrant_collection="hawki_dataset_a",
                neo4j_namespace="hawki_dataset_a",
                embedding_provider="ollama",
                embedding_model="bge-m3",
            )
        )
        conn.execute(
            dataset_grants.insert().values(
                tenant_id="default",
                dataset_id="dataset-a",
                principal_type="user",
                principal_id="42",
                permission="ingest",
            )
        )
    principal = AuthenticatedPrincipal(
        PrincipalKind.USER,
        "42",
        "default",
        "hawki-bff",
        frozenset({"rag:ingest"}),
        "test",
        datetime(2100, 1, 1, tzinfo=timezone.utc),
    )
    authenticator = Mock(authenticate=Mock(return_value=principal))

    async def start(**kwargs):
        # Simulate an acknowledged indexing activity at the network boundary.
        # The real repository handles its signed protocol state machine.
        value = kwargs["workflow_input"]
        writer = WriterEvent(
            schema_version=1,
            event_id="fixture-writer",
            action="acquire",
            source_id=value["source_id"],
            job_id=value["job_id"],
            workflow_id=kwargs["workflow_id"],
            run_id="run-1",
            activity_id="fixture-finished",
            attempt=1,
            targets=[],
        )
        port.repository.record_writer(writer)
        port.repository.record_writer(writer.model_copy(update={"action": "release"}))
        return TemporalExecution(kwargs["workflow_id"], "run-1")

    port = SimpleNamespace(
        start_text_ingest_workflow=AsyncMock(side_effect=start),
        reconcile_start=AsyncMock(return_value=None),
        cancel_workflow_and_wait=AsyncMock(),
    )
    control = WorkflowControl(
        OperationRepository(engine, dataset_source="environment"), port
    )
    settings = TextIngestionSettings(enabled=True, shared_root=str(tmp_path))
    repository = TextOperationRepository(engine)
    port.repository = repository
    vectors = Mock()
    service = TextIngestionService(
        repository=repository,
        artifacts=TextArtifactStorage(str(tmp_path)),
        vectors=vectors,
        settings=settings,
        policy=LocalGrantPolicy(DatabaseGrantReader(engine)),
        query_settings=QuerySettings(
            {
                "dataset-a": DatasetScope(
                    "owned_collection", "ollama", "embed", "unused_graph"
                )
            },
            {"ollama": ProviderModels("chat", "vision")},
        ),
        workflow_control=control,
    )
    app = create_app(
        authenticator=authenticator,
        workflow_control=control,
        text_ingestion_settings=settings,
        text_ingestion_service=service,
        text_ingestion_secret="event-test-secret",
    )
    app.add_api_route(
        "/v1/text-ingestions", ingest_text, methods=["POST"], include_in_schema=False
    )
    yield SimpleNamespace(
        app=app,
        engine=engine,
        service=service,
        repository=repository,
        principal=principal,
        authenticator=authenticator,
        vectors=vectors,
        port=port,
        root=tmp_path,
        control=control,
    )
    engine.dispose()
