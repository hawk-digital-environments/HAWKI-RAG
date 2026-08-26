"""In-process indexer scenarios replacing the retired bridge ingestion API."""

from __future__ import annotations

from pathlib import Path
from typing import Any

import pytest

from hawki_indexer_worker.domain.errors import IndexingValidationError
from hawki_indexer_worker.domain.models import IngestDocument
from hawki_indexer_worker.indexing.dependencies import IngestWorkflowDependencies
from hawki_indexer_worker.indexing.orchestration import ingest_documents
from hawki_indexer_worker.indexing.page_state import QdrantPageState
from hawki_indexer_worker.indexing.request import IndexRequest


class RecordingProvider:
    embed_model = "bge-m3"
    rag_model = "chat"
    vision_model = "vision"

    def __init__(self) -> None:
        self.embedded: list[str] = []

    def embed(self, text: str) -> list[float]:
        self.embedded.append(text)
        return [0.1, 0.2, 0.3]


class RecordingQdrant:
    def __init__(self) -> None:
        self.collection = "default"
        self.points: list[dict[str, Any]] = []
        self.operations: list[tuple[str, str | None]] = []

    def set_collection(self, collection: str) -> None:
        self.collection = collection

    def find_points_by_payload(
        self,
        filters: dict[str, Any],
        *,
        limit: int = 1,
    ) -> list[dict[str, Any]]:
        matches = [
            point
            for point in self.points
            if all(
                point.get("payload", {}).get(key) == value
                for key, value in filters.items()
            )
        ]
        return matches[:limit]

    def ensure_collection(self, vector_size: int, *, distance: str) -> None:
        assert vector_size == 3
        assert distance == "Cosine"

    def upsert_points(
        self,
        points: list[dict[str, Any]],
        *,
        batch_size: int,
        idempotency_key: str | None = None,
    ) -> None:
        assert batch_size > 0
        self.operations.append(("upsert", idempotency_key))
        self.points.extend(points)

    def delete_by_doc_id(
        self,
        doc_id: str,
        *,
        idempotency_key: str | None = None,
    ) -> dict[str, str]:
        self.operations.append(("delete", idempotency_key))
        self.points = [
            point
            for point in self.points
            if point.get("payload", {}).get("doc_id") != doc_id
        ]
        return {"status": "ok"}


def _request(text: str, *, operation_id: str = "index-op-1") -> IndexRequest:
    return IndexRequest(
        docs=[
            IngestDocument(
                id="fee-document-1",
                text=text,
                payload={
                    "title": "Fees",
                    "source_url": "https://example.test/fees",
                    "source_format": "markdown",
                    "job_id": "job-1",
                },
            )
        ],
        provider="ollama",
        embedding_model="bge-m3",
        collection="dataset-1",
        idempotency_key=operation_id,
    )


def _dependencies(qdrant: RecordingQdrant) -> IngestWorkflowDependencies:
    return IngestWorkflowDependencies(
        vector_writer_factory=lambda: qdrant,
        graph_writer_factory=lambda **_kwargs: None,
        page_state_factory=QdrantPageState,
    )


class TestInProcessIndexingFlow:
    """Prove indexing owns validation and writes without bridge HTTP calls."""

    def test_validated_input_is_indexed_directly_with_stable_idempotency(
        self,
        tmp_path: Path,
    ) -> None:
        qdrant = RecordingQdrant()
        provider = RecordingProvider()

        response = ingest_documents(
            _request("The semester fee is listed in this document."),
            rag_service=object(),
            get_provider=lambda _name: provider,
            dependencies=_dependencies(qdrant),
        )

        assert response["ok"] is True
        assert response["summary"]["qdrant_preview"]["collection"] == "dataset-1"
        assert response["points"] == 1
        assert qdrant.operations == [("upsert", "index-op-1")]
        assert provider.embedded == ["The semester fee is listed in this document."]

    def test_invalid_document_is_rejected_before_any_store_write(
        self,
        tmp_path: Path,
    ) -> None:
        qdrant = RecordingQdrant()

        with pytest.raises(IndexingValidationError, match="No valid content"):
            ingest_documents(
                _request("   "),
                rag_service=object(),
                get_provider=lambda _name: (_ for _ in ()).throw(
                    AssertionError("provider must not resolve for invalid content")
                ),
                dependencies=_dependencies(qdrant),
            )

        assert qdrant.operations == []
        assert qdrant.points == []

    def test_store_failure_propagates_from_the_indexer_boundary(
        self,
        tmp_path: Path,
    ) -> None:
        class FailingQdrant(RecordingQdrant):
            def upsert_points(
                self,
                points: list[dict[str, Any]],
                *,
                batch_size: int,
                idempotency_key: str | None = None,
            ) -> None:
                self.operations.append(("upsert", idempotency_key))
                raise RuntimeError("Qdrant write failed")

        qdrant = FailingQdrant()

        with pytest.raises(RuntimeError, match="Qdrant write failed"):
            ingest_documents(
                _request("Index this content.", operation_id="failed-op"),
                rag_service=object(),
                get_provider=lambda _name: RecordingProvider(),
                dependencies=_dependencies(qdrant),
            )

        assert qdrant.operations == [("upsert", "failed-op")]
        assert qdrant.points == []
