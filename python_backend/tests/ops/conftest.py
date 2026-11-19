"""Real SQL dataset/grant/operation state; graph and Qdrant network ports are fake."""

import asyncio
from datetime import datetime, timezone
from types import SimpleNamespace
from unittest.mock import Mock

import httpx
import pytest
import sqlalchemy as sa
from sqlalchemy.pool import StaticPool

from hawki_backend.auth.errors import AuthenticationError, AuthFailureReason
from hawki_backend.auth.models import AuthenticatedPrincipal, PrincipalKind
from hawki_backend.authorization import LocalGrantPolicy
from hawki_backend.persistence.datasets import DatasetStore
from hawki_backend.persistence.grants import DatabaseGrantReader
from hawki_backend.persistence.schema import datasets, dataset_grants, metadata
from hawki_backend.managed_documents.repository import ManagedRepository
from hawki_backend.main import create_app
from hawki_backend.ops.service import OpsService
from hawki_backend.query.settings import QuerySettings, ProviderModels
from hawki_graph_store.responses import scoped_node_id


def configure(engine):
    with engine.begin() as connection:
        for tenant, dataset, status, enabled in [
            ("a", "one", "active", True),
            ("a", "two", "active", True),
            ("b", "foreign", "active", True),
            ("a", "archived", "archived", True),
            ("a", "disabled", "active", False),
        ]:
            connection.execute(
                datasets.insert().values(
                    tenant_id=tenant,
                    dataset_id=dataset,
                    name=dataset,
                    status=status,
                    graph_enabled=enabled,
                    qdrant_collection="physical-vector-" + dataset,
                    neo4j_namespace="physical-graph-" + dataset,
                    embedding_provider="ollama",
                    embedding_model="embed",
                )
            )
            connection.execute(
                dataset_grants.insert().values(
                    tenant_id=tenant,
                    dataset_id=dataset,
                    principal_type="user",
                    principal_id="reader",
                    permission="query",
                )
            )

    def authenticate(token):
        if token == "invalid":
            raise AuthenticationError(AuthFailureReason.INVALID_SIGNATURE)
        return AuthenticatedPrincipal(
            PrincipalKind.USER,
            "reader" if token != "ungranted" else "other",
            "b" if token == "other-tenant" else "a",
            "hawki-bff",
            frozenset({"rag:datasets:manage"})
            if token in {"manager", "other-manager"}
            else frozenset({"rag:query"}),
            "test",
            datetime(2100, 1, 1, tzinfo=timezone.utc),
        )

    graph = Mock()

    def graph_factory(dataset, namespace):
        graph.graph_overview.return_value = {
            "ok": True,
            "nodes": [
                {
                    "id": scoped_node_id(dataset, namespace, "shared"),
                    "label": "Shared",
                    "properties": {},
                    "source_document_ids": ["known", "missing"],
                }
            ],
            "edges": [],
            "warnings": [],
        }
        graph.graph_counts.return_value = {"entities": 2, "triplets": 1}
        graph.clear_dataset_graph.return_value = {
            "deleted_nodes": 2,
            "deleted_relationships": 1,
        }
        return graph

    factory = Mock(side_effect=graph_factory)
    catalog = Mock(
        available_collections=Mock(
            return_value={
                "physical-vector-one",
                "physical-vector-two",
                "physical-vector-foreign",
                "physical-vector-disabled",
            }
        ),
        point_count=Mock(return_value=3),
    )
    settings = QuerySettings(models={"ollama": ProviderModels("chat", "vision")})
    policy = LocalGrantPolicy(DatabaseGrantReader(engine))
    ops = OpsService(
        DatasetStore(engine),
        policy,
        settings,
        catalog,
        engine,
        ManagedRepository(engine),
        factory,
    )
    app = create_app(
        authenticator=Mock(authenticate=authenticate), policy=policy, ops_service=ops
    )
    return SimpleNamespace(
        engine=engine, app=app, ops=ops, graph=graph, factory=factory, catalog=catalog
    )


@pytest.fixture
def env(monkeypatch):
    for name in tuple(__import__("os").environ):
        if name.startswith(("HAWKI_BACKEND_", "HAWKI_WORKFLOW_")):
            monkeypatch.delenv(name)
    engine = sa.create_engine(
        "sqlite://",
        poolclass=StaticPool,
        connect_args={"check_same_thread": False},
        execution_options={"schema_translate_map": {"hawki_backend": None}},
    )
    metadata.create_all(engine)
    yield configure(engine)
    engine.dispose()


def call(
    env,
    path="graph/overview?dataset_id=one",
    *,
    token="reader",
    method="GET",
    body=None,
):
    async def send():
        async with httpx.AsyncClient(
            transport=httpx.ASGITransport(app=env.app), base_url="http://test"
        ) as client:
            return await client.request(
                method,
                "/v1/ops/" + path,
                headers={"Authorization": "Bearer " + token} if token else {},
                json=body,
            )

    return asyncio.run(send())
