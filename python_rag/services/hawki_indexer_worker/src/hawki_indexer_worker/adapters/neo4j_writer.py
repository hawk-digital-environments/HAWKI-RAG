"""Neo4j adapter implementing the indexer-owned graph writer port."""

from __future__ import annotations

from collections.abc import Callable
from contextlib import AbstractContextManager, nullcontext
from dataclasses import dataclass, replace

from hawki_graph_store.graph import Neo4jGraph
from hawki_graph_store.settings import load_neo4j_settings
from hawki_indexer_worker.domain.ports import GraphWriterPort


@dataclass(slots=True)
class Neo4jWriter:
    """Delegate scoped graph writes to the graph-store client."""

    client: Neo4jGraph

    def upsert_triplets(
        self,
        triplets: list[tuple[str, str, str]],
        *,
        doc_id: str,
        request_id: str | None,
        dataset_id: str,
        neo4j_namespace: str,
    ) -> object:
        return self.client.upsert_triplets(
            triplets,
            doc_id=doc_id,
            request_id=request_id,
            dataset_id=dataset_id,
            neo4j_namespace=neo4j_namespace,
        )

    def delete_by_doc_id(
        self,
        doc_id: str,
        *,
        request_id: str | None = None,
    ) -> object:
        return self.client.delete_by_doc_id(doc_id, request_id=request_id)

    def delete_scoped_document(
        self, doc_id: str, *, request_id: str | None = None
    ) -> dict[str, object]:
        return self.client.delete_scoped_document(doc_id, request_id=request_id)

    def close(self) -> None:
        self.client.close()


def create_neo4j_writer(
    database: str | None = None,
    *,
    dataset_id: str | None = None,
    neo4j_namespace: str | None = None,
) -> GraphWriterPort:
    return Neo4jWriter(
        Neo4jGraph(
            database=database,
            dataset_id=dataset_id,
            neo4j_namespace=neo4j_namespace,
        )
    )


class RegisteredGraphWriter(Neo4jWriter):
    def __init__(
        self,
        client: Neo4jGraph,
        failed_write: Callable[[], None],
        *,
        authorize: Callable[[str, str, str], None],
        dataset_id: str,
        neo4j_namespace: str,
        write_guard: Callable[[], AbstractContextManager[None]] = nullcontext,
    ) -> None:
        super().__init__(client)
        self.failed_write = failed_write
        self.authorize = authorize
        self.write_guard = write_guard
        self.dataset_id = dataset_id
        self.neo4j_namespace = neo4j_namespace

    def upsert_triplets(
        self,
        triplets: list[tuple[str, str, str]],
        *,
        doc_id: str,
        request_id: str | None,
        dataset_id: str,
        neo4j_namespace: str,
    ) -> object:
        with self.write_guard():
            if dataset_id != self.dataset_id or neo4j_namespace != self.neo4j_namespace:
                raise RuntimeError("Graph mutation exceeds client scope")
            self.authorize(doc_id, dataset_id, neo4j_namespace)
            try:
                return super().upsert_triplets(
                    triplets,
                    doc_id=doc_id,
                    request_id=request_id,
                    dataset_id=dataset_id,
                    neo4j_namespace=neo4j_namespace,
                )
            except BaseException:
                self.failed_write()
                raise

    def delete_by_doc_id(self, doc_id: str, *, request_id: str | None = None) -> object:
        return self.delete_scoped_document(doc_id, request_id=request_id)

    def delete_scoped_document(
        self, doc_id: str, *, request_id: str | None = None
    ) -> dict[str, object]:
        with self.write_guard():
            self.authorize(doc_id, self.dataset_id, self.neo4j_namespace)
            try:
                return super().delete_scoped_document(doc_id, request_id=request_id)
            except BaseException:
                self.failed_write()
                raise


def create_registered_graph_writer(
    failed_write: Callable[[], None],
    database: str | None = None,
    *,
    dataset_id: str | None = None,
    neo4j_namespace: str | None = None,
    authorize: Callable[[str, str, str], None],
    write_guard: Callable[[], AbstractContextManager[None]] = nullcontext,
) -> GraphWriterPort:
    client = Neo4jGraph(
        database=database,
        dataset_id=dataset_id,
        neo4j_namespace=neo4j_namespace,
        allow_database_fallback=False,
        settings=replace(load_neo4j_settings(database), max_transaction_retry_time=0),
    )
    return RegisteredGraphWriter(
        client,
        failed_write,
        authorize=authorize,
        dataset_id=dataset_id,
        neo4j_namespace=neo4j_namespace,
        write_guard=write_guard,
    )


__all__ = ["Neo4jWriter", "create_neo4j_writer", "create_registered_graph_writer"]
