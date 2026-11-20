from __future__ import annotations

import uuid
from typing import Any

from hawki_indexer_worker.domain.models import IngestDocument
from hawki_indexer_worker.indexing.dependencies import IngestWorkflowDependencies
from hawki_indexer_worker.indexing.page_state import QdrantPageState
from hawki_indexer_worker.indexing.request import IndexRequest


class RecordingProvider:
    embed_model = "embed-test"

    def __init__(self, *, fail_on: str | None = None) -> None:
        self.fail_on = fail_on
        self.embedded: list[str] = []

    def embed(self, text: str) -> list[float]:
        self.embedded.append(text)
        if text == self.fail_on:
            raise RuntimeError("provider response included sensitive details")
        return [0.1, 0.2, 0.3]


class MemoryQdrant:
    def __init__(self) -> None:
        self.collection = "default"
        self.points: dict[str, dict[str, Any]] = {}
        self.upsert_batches: list[list[str]] = []
        self.fail_on_batch: int | None = None
        self.fail_completion = False
        self.fail_payload_refresh_after: int | None = None
        self.completion_writes = 0
        self.payload_refresh_writes = 0
        self.delete_calls = 0

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
            for point in self.points.values()
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
        del idempotency_key
        for batch_number, start in enumerate(
            range(0, len(points), batch_size), start=1
        ):
            batch = points[start : start + batch_size]
            self.upsert_batches.append([str(point["id"]) for point in batch])
            if self.fail_on_batch == batch_number:
                raise RuntimeError("Qdrant batch failed")
            for point in batch:
                self.points[str(point["id"])] = {
                    **point,
                    "payload": dict(point["payload"]),
                }

    def set_payload(
        self,
        point_ids: list[str],
        payload: dict[str, Any],
        *,
        idempotency_key: str | None = None,
    ) -> None:
        del idempotency_key
        is_completion = "rawki_document_completion_fingerprint" in payload
        if is_completion and self.fail_completion:
            raise RuntimeError("completion write failed")
        if is_completion:
            self.completion_writes += 1
        else:
            self.payload_refresh_writes += 1
        for index, point_id in enumerate(point_ids, start=1):
            self.points[point_id]["payload"].update(payload)
            if (
                not is_completion
                and self.fail_payload_refresh_after is not None
                and index >= self.fail_payload_refresh_after
            ):
                raise RuntimeError("payload refresh failed")

    def delete_by_doc_id(
        self,
        doc_id: str,
        *,
        idempotency_key: str | None = None,
    ) -> dict[str, str]:
        del idempotency_key
        self.delete_calls += 1
        self.points = {
            point_id: point
            for point_id, point in self.points.items()
            if point.get("payload", {}).get("doc_id") != doc_id
        }
        return {"status": "ok"}

    def completion_points(self) -> list[dict[str, Any]]:
        return [
            point
            for point in self.points.values()
            if point.get("payload", {}).get("rawki_document_complete") is True
        ]


def direct_text_request(
    text: str,
    *,
    operation_id: str,
    chunk_chars: int = 5,
    display_name: str = "Direct document",
    source_url: str = "https://example.test/direct",
    metadata: dict[str, Any] | None = None,
) -> IndexRequest:
    return IndexRequest(
        docs=[
            IngestDocument(
                id="direct-document",
                text=text,
                payload={
                    "ingestion_mode": "direct_text",
                    "source_format": "markdown",
                    "source_id": "source-direct",
                    "job_id": "job-direct",
                    "external_document_id": "external-direct",
                    "display_name": display_name,
                    "content_format": "markdown",
                    "source_url": source_url,
                    "metadata": metadata or {},
                },
            )
        ],
        provider="fake",
        collection="direct-text",
        chunk_chars=chunk_chars,
        chunk_overlap=0,
        batch_size=2,
        idempotency_key=operation_id,
    )


def direct_text_dependencies(qdrant: MemoryQdrant) -> IngestWorkflowDependencies:
    return IngestWorkflowDependencies(
        vector_writer_factory=lambda: qdrant,
        graph_writer_factory=lambda **_kwargs: None,
        page_state_factory=QdrantPageState,
    )


def expected_point_ids() -> set[str]:
    return {
        str(uuid.uuid5(uuid.NAMESPACE_URL, f"direct-document:{index}"))
        for index in range(3)
    }


__all__ = [
    "MemoryQdrant",
    "RecordingProvider",
    "direct_text_dependencies",
    "direct_text_request",
    "expected_point_ids",
]
