"""State tests fake the crawler; production safety remains explicitly blocked."""

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
from hawki_backend.main import create_app
from hawki_backend.persistence.grants import DatabaseGrantReader
from hawki_backend.persistence.schema import datasets, dataset_grants
from hawki_backend.query.settings import DatasetScope, QuerySettings, ProviderModels
from hawki_backend.url_ingestion.repository import UrlRepository
from hawki_backend.url_ingestion.schema import TABLES
from hawki_backend.managed_documents.schema import TABLES as MANAGED_TABLES
from hawki_backend.text_ingestion.schema import ingest_text_operations
from hawki_backend.url_ingestion.service import UrlIngestionService
from hawki_backend.url_ingestion.settings import UrlIngestionSettings
from hawki_backend.url_ingestion.validation import UrlSafetyValidator, UrlStartInput
from hawki_backend.workflow_control.repository import OperationRepository
from hawki_backend.workflow_control.schema import operations
from hawki_backend.workflow_control.service import WorkflowControl
from hawki_workflow_control.temporal_client import TemporalExecution


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
        return TemporalExecution(
            kwargs["workflow_id"],
            "run-" + kwargs["workflow_input"]["url_ingestion_operation_id"],
        )

    async def workflow_status(**kwargs):
        return {key: kwargs[key] for key in ("workflow_id", "run_id")} | {
            "status": "RUNNING"
        }

    port = SimpleNamespace(
        start_ingest_workflow=AsyncMock(side_effect=start),
        reconcile_start=AsyncMock(return_value=None),
        cancel_workflow_and_wait=AsyncMock(),
        delete_schedule=AsyncMock(),
        workflow_status=AsyncMock(side_effect=workflow_status),
        acknowledge_url_terminal=AsyncMock(),
    )
    control = WorkflowControl(OperationRepository(engine), port)
    instance = UrlIngestionService(
        repository=UrlRepository(engine),
        policy=LocalGrantPolicy(DatabaseGrantReader(engine)),
        query_settings=QuerySettings(
            {
                "web": DatasetScope(
                    "historic_vectors", "ollama", "embed", "historic_graph", True
                )
            },
            {"ollama": ProviderModels("chat", "vision")},
        ),
        control=control,
        settings=UrlIngestionSettings(True, "http://scraper.test", str(root)),
        cleanup=Mock(),
        safety=UrlSafetyValidator(lambda host, port: ("93.184.215.14",)),
        crawler=Mock(),
        quiescence=Mock(),
        neo4j_database="historic_database",
    )
    authenticator = Mock(authenticate=Mock(return_value=principal))
    app = create_app(
        authenticator=authenticator,
        url_ingestion_service=instance,
        workflow_control_secret="url-test-secret",
    )
    return SimpleNamespace(
        engine=engine,
        root=root,
        principal=principal,
        service=instance,
        repository=instance.repository,
        port=port,
        control=control,
        app=app,
    )


def seed(engine):
    with engine.begin() as connection:
        connection.execute(
            datasets.insert().values(
                tenant_id="default",
                dataset_id="web",
                status="active",
                name="Web",
                qdrant_collection="hawki_web",
                neo4j_namespace="hawki_web",
                embedding_provider="ollama",
                embedding_model="bge-m3",
            )
        )
        connection.execute(
            dataset_grants.insert().values(
                tenant_id="default",
                dataset_id="web",
                principal_type="user",
                principal_id="42",
                permission="ingest",
            )
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
    for table in (
        datasets,
        dataset_grants,
        operations,
        *MANAGED_TABLES,
        ingest_text_operations,
        *TABLES,
    ):
        table.create(engine)
    seed(engine)
    yield configure(engine, tmp_path)
    engine.dispose()


def create(env, key="one", **changes):
    return asyncio.run(
        env.service.start(
            env.principal,
            UrlStartInput.model_validate(
                {
                    "dataset_id": "web",
                    "source_url": "https://Example.org/a?b=1#f",
                    **changes,
                }
            ),
            key,
        )
    )[1]


def request(env, path, body=None, method="POST", headers=None):
    async def send():
        async with httpx.AsyncClient(
            transport=httpx.ASGITransport(app=env.app), base_url="http://test"
        ) as client:
            return await client.request(
                method,
                path,
                json=body,
                headers=headers
                if headers is not None
                else {"Authorization": "Bearer test", "Idempotency-Key": "one"},
            )

    return asyncio.run(send())
