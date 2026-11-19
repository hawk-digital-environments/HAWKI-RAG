"""Graph authority built from the existing dataset, query and graph adapters."""

from dataclasses import replace
from collections.abc import Callable

from fastapi import HTTPException
from sqlalchemy.engine import Engine

from hawki_backend.authorization import (
    AuthorizationAction,
    AuthorizationRequest,
    AuthorizationPort,
)
from hawki_backend.auth.models import AuthenticatedPrincipal
from hawki_backend.persistence.datasets import DatasetStore
from hawki_backend.persistence.datasets import DatasetRecord
from hawki_backend.datasets.catalog import QdrantCatalog
from hawki_backend.managed_documents.repository import ManagedRepository
from hawki_backend.query.settings import QuerySettings
from hawki_graph_store import Neo4jGraph, load_neo4j_settings

from .admission import prepare_clear, finish_clear


def build_graph(dataset: str, namespace: str) -> Neo4jGraph:
    return Neo4jGraph(
        dataset_id=dataset,
        neo4j_namespace=namespace,
        allow_database_fallback=False,
        settings=replace(load_neo4j_settings(), max_transaction_retry_time=0),
    )


class OpsService:
    def __init__(
        self,
        store: DatasetStore,
        policy: AuthorizationPort,
        settings: QuerySettings,
        catalog: QdrantCatalog,
        engine: Engine,
        documents: ManagedRepository,
        graph_factory: Callable[[str, str], Neo4jGraph] = build_graph,
    ) -> None:
        self.store, self.policy, self.settings = store, policy, settings
        self.catalog, self.engine, self.documents = catalog, engine, documents
        self.graph_factory = graph_factory

    def dataset(
        self,
        principal: AuthenticatedPrincipal,
        dataset_id: str,
        *,
        manager: bool = False,
    ) -> DatasetRecord:
        if not manager and not self.policy.decide(
            AuthorizationRequest(
                principal=principal,
                action=AuthorizationAction.QUERY,
                dataset_id=dataset_id,
            )
        ):
            raise HTTPException(404, "dataset_not_found")
        record = self.store.get(principal.tenant_id, dataset_id)
        if record is None or record.status != "active":
            raise HTTPException(404, "dataset_not_found")
        if not record.graph_enabled or not record.neo4j_namespace:
            raise HTTPException(409, "dataset_graph_disabled")
        return record

    def read(
        self, principal: AuthenticatedPrincipal, dataset_id: str, method: str, **kwargs
    ) -> dict:
        record = self.dataset(principal, dataset_id)
        graph = self.graph_factory(dataset_id, record.neo4j_namespace)
        try:
            result = getattr(graph, method)(**kwargs)
        finally:
            graph.close()
        payload = (
            {"ok": True, "results": result, "warnings": []}
            if isinstance(result, list)
            else result
        )
        nodes = payload.get("nodes", payload.get("results", []))
        ids = {doc for node in nodes for doc in node.get("source_document_ids", [])}
        sources = self.documents.graph_sources(principal.tenant_id, dataset_id, ids)
        for node in nodes:
            node["source_documents"] = [
                sources.get(doc, {"docId": doc, "label": doc, "missing": True})
                for doc in node.get("source_document_ids", [])
            ]
        return payload | {"dataset_id": dataset_id}

    def clear(self, principal: AuthenticatedPrincipal, dataset_id: str) -> dict:
        self.dataset(principal, dataset_id, manager=True)
        operation, namespace = prepare_clear(
            self.engine, principal.tenant_id, dataset_id
        )
        try:
            graph = self.graph_factory(dataset_id, namespace)
            try:
                counts = graph.clear_dataset_graph()
            finally:
                graph.close()
        except Exception:
            finish_clear(self.engine, operation, None)
            raise
        finish_clear(self.engine, operation, counts)
        return {"ok": True, "dataset_id": dataset_id, **counts}

    def stats(self, principal: AuthenticatedPrincipal) -> dict:
        collections, entities, triplets = [], 0, 0
        available = self.catalog.available_collections()
        if available is None:
            raise HTTPException(503, "ops_unavailable")
        for record in self.store.active_records(principal.tenant_id):
            if record.qdrant_collection not in available:
                raise HTTPException(503, "ops_unavailable")
            count = self.catalog.point_count(record.qdrant_collection)
            if count is None:
                raise HTTPException(503, "ops_unavailable")
            collections.append(
                {
                    "dataset_id": record.dataset_id,
                    "name": record.qdrant_collection,
                    "count": count,
                }
            )
            if record.graph_enabled and record.neo4j_namespace:
                graph = self.graph_factory(record.dataset_id, record.neo4j_namespace)
                try:
                    counts = graph.graph_counts()
                finally:
                    graph.close()
                entities += counts["entities"]
                triplets += counts["triplets"]
        return {
            "ok": True,
            "authority": "python",
            "qdrant": {"ok": True, "collections": collections},
            "neo4j": {"ok": True, "entities": entities, "triplets": triplets},
        }

    def config(self, principal: AuthenticatedPrincipal) -> dict:
        return {
            "ok": True,
            "authority": "python",
            "effective": True,
            "managedBy": "deployment",
            "models": {
                provider: {
                    "chat_model": models.chat_model,
                    "vision_model": models.vision_model,
                }
                for provider, models in self.settings.models.items()
            },
            "datasets": [
                {
                    "dataset_id": row.dataset_id,
                    "graph_enabled": row.graph_enabled,
                    "embedding_provider": row.embedding_provider,
                    "embedding_model": row.embedding_model,
                }
                for row in self.store.active_records(principal.tenant_id)
            ],
            "reranker": self.settings.reranker,
            "rerank_top_n": self.settings.rerank_top_n,
            "limits": {"overview": 300, "search": 50, "expansion": 250, "depth": 3},
        }
