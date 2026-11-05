"""Real SQL, artifacts, grants and Epoch 8 control; only network ports are fake."""

import asyncio
from datetime import datetime, timezone
from types import SimpleNamespace
from unittest.mock import AsyncMock, Mock

import httpx
import pytest
import sqlalchemy as sa
from sqlalchemy.pool import StaticPool

from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind
from hawki_backend.authorization import LocalGrantPolicy
from hawki_backend.persistence.grants import DatabaseGrantReader
from hawki_backend.persistence.schema import datasets, dataset_grants
from hawki_backend.managed_documents.artifacts import UploadStorage
from hawki_backend.managed_documents.repository import ManagedRepository
from hawki_backend.managed_documents.service import ManagedService
from hawki_backend.managed_documents.schema import TABLES
from hawki_backend.main import create_app
from hawki_backend.query.settings import DatasetScope, QuerySettings, ProviderModels
from hawki_backend.workflow_control.repository import OperationRepository
from hawki_backend.workflow_control.schema import operations
from hawki_backend.workflow_control.service import WorkflowControl
from hawki_workflow_control.temporal_client import TemporalExecution


def seed(engine):
    with engine.begin() as connection:
        connection.execute(
            datasets.insert().values(
                tenant_id="default", dataset_id="files", status="active"
            )
        )
        connection.execute(
            dataset_grants.insert().values(
                tenant_id="default",
                dataset_id="files",
                principal_type="user",
                principal_id="42",
                permission="ingest",
            )
        )


def configure(engine, root):
    principal = AuthenticatedPrincipal(
        PrincipalKind.USER,
        "42",
        "default",
        "hawki-bff",
        frozenset({"rag:ingest"}),
        "test",
        datetime(2100, 1, 1, tzinfo=timezone.utc),
    )

    async def start(**kwargs):
        return TemporalExecution(kwargs["workflow_id"], "run-1")

    port = SimpleNamespace(
        start_ingest_workflow=AsyncMock(side_effect=start),
        reconcile_start=AsyncMock(return_value=None),
        cancel_workflow_and_wait=AsyncMock(),
    )
    control = WorkflowControl(OperationRepository(engine), port)
    service = ManagedService(
        repository=ManagedRepository(engine),
        storage=UploadStorage(str(root)),
        policy=LocalGrantPolicy(DatabaseGrantReader(engine)),
        query_settings=QuerySettings(
            {
                "files": DatasetScope(
                    "historic_vectors", "ollama", "embed", "historic_graph", True
                )
            },
            {"ollama": ProviderModels("chat", "vision")},
        ),
        control=control,
        cleanup=Mock(),
    )
    authenticator = Mock(authenticate=Mock(return_value=principal))
    app = create_app(
        authenticator=authenticator,
        managed_document_service=service,
        workflow_control_secret="callback-secret",
    )
    return SimpleNamespace(
        engine=engine,
        principal=principal,
        service=service,
        repository=service.repository,
        port=port,
        control=control,
        app=app,
        authenticator=authenticator,
        root=root,
    )


@pytest.fixture
def env(tmp_path, monkeypatch):
    import os

    for key in tuple(os.environ):
        if key.startswith(("HAWKI_BACKEND_", "HAWKI_WORKFLOW_", "TEMPORAL_")):
            monkeypatch.delenv(key)
    engine = sa.create_engine(
        "sqlite://",
        poolclass=StaticPool,
        connect_args={"check_same_thread": False},
        execution_options={"schema_translate_map": {"hawki_backend": None}},
    )
    for table in (datasets, dataset_grants, operations, *TABLES):
        table.create(engine)
    seed(engine)
    yield configure(engine, tmp_path)
    engine.dispose()


def send(
    env,
    path="/v1/documents",
    method="POST",
    key="key-1",
    data=None,
    files=None,
    auth=True,
):
    async def call():
        headers = {"Authorization": "Bearer test"} if auth else {}
        if key is not None:
            headers["Idempotency-Key"] = key
        async with httpx.AsyncClient(
            transport=httpx.ASGITransport(app=env.app), base_url="http://test"
        ) as client:
            return await client.request(
                method,
                path,
                headers=headers,
                data={"dataset_id": "files"} if data is None else data,
                files=[("file", ("a.txt", b"hello"))]
                if files is None and method != "DELETE"
                else files,
            )

    return asyncio.run(call())


def request_json(env, path, payload):
    async def call():
        async with httpx.AsyncClient(
            transport=httpx.ASGITransport(app=env.app), base_url="http://test"
        ) as client:
            return await client.post(
                path, json=payload, headers={"Authorization": "Bearer test"}
            )

    return asyncio.run(call())
