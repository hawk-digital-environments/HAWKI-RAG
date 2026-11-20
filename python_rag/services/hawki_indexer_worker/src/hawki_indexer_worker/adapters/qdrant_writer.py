"""Indexer composition surface for Qdrant writes."""

from collections.abc import Callable
from contextlib import AbstractContextManager, nullcontext
from dataclasses import replace
from typing import Any

from hawki_vector_store.client import QdrantHTTP, ScopedCollectionNotReadyError
from hawki_vector_store.settings import qdrant_settings_from_env
from hawki_indexer_worker.domain.ports import VectorWriterPort
from hawki_indexer_worker.indexing.point_identity import deterministic_point_id


def create_qdrant_writer() -> VectorWriterPort:
    return QdrantHTTP()


class RegisteredVectorWriter(QdrantHTTP):
    def __init__(
        self,
        failed_write: Callable[[], None],
        *,
        collection: str,
        source_id: str,
        dataset_id: str,
        authorize: Callable[[dict[str, Any]], None],
        write_guard: Callable[[], AbstractContextManager[None]] = nullcontext,
    ) -> None:
        settings = qdrant_settings_from_env()
        super().__init__(
            settings=replace(
                settings,
                max_attempts=1,
                retry_attempts_by_operation={
                    name: 1 for name in settings.retry_attempts_by_operation
                },
            )
        )
        self.failed_write = failed_write
        self.authorize = authorize
        self.write_guard = write_guard
        self.ownership = {"source_id": source_id, "dataset_id": dataset_id}
        self.select_scoped_collection(collection)

    def scroll_by_filter(
        self, filter_body: dict[str, Any], *, limit: int, offset: str | None = None
    ) -> list[dict[str, Any]]:
        # A first ingestion has no page history yet. Keep the granted collection
        # selected; the normal vector commit creates it with the embedding size.
        try:
            return super().scroll_by_filter(filter_body, limit=limit, offset=offset)
        except ScopedCollectionNotReadyError:
            return []

    def ensure_collection(self, vector_size: int, distance: str = "Cosine") -> None:
        with self.write_guard():
            try:
                return super().ensure_collection(vector_size, distance)
            except BaseException:
                self.failed_write()
                raise

    def upsert(
        self, points: list[dict[str, Any]], *, idempotency_key: str | None = None
    ) -> None:
        with self.write_guard():
            for point in points:
                payload = point.get("payload") or {}
                self.authorize(payload)
                if str(point.get("id")) != deterministic_point_id(
                    payload["doc_id"], int(payload.get("chunk_index", 0))
                ):
                    raise RuntimeError(
                        "Vector point identity exceeds registered document scope"
                    )
            try:
                return super().upsert(points, idempotency_key=idempotency_key)
            except BaseException:
                self.failed_write()
                raise

    def set_payload(
        self,
        point_ids: list[str | int],
        payload: dict[str, Any],
        *,
        idempotency_key: str | None = None,
    ) -> None:
        with self.write_guard():
            # Completion updates omit ownership fields; verify the selected points
            # before allowing a payload-only mutation, including retry-existing points.
            if point_ids:
                points = self.scroll_by_filter(
                    {"must": [{"has_id": point_ids}]}, limit=len(set(point_ids))
                )
                if {str(point["id"]) for point in points} != {
                    str(point_id) for point_id in point_ids
                }:
                    raise RuntimeError("Payload mutation has unknown point ownership")
                for point in points:
                    self.authorize(point.get("payload") or {})
                    self.authorize({**(point.get("payload") or {}), **payload})
            try:
                return super().set_payload(
                    point_ids, payload, idempotency_key=idempotency_key
                )
            except BaseException:
                self.failed_write()
                raise

    def delete_by_filter(
        self, filter_body: dict[str, Any], *, idempotency_key: str | None = None
    ) -> None:
        with self.write_guard():
            matches = {
                condition["key"]: condition["match"]["value"]
                for condition in filter_body.get("must", [])
                if "key" in condition
                and isinstance(condition.get("match"), dict)
                and "value" in condition["match"]
            }
            self.authorize(matches)
            try:
                return super().delete_by_filter(
                    filter_body, idempotency_key=idempotency_key
                )
            except BaseException:
                self.failed_write()
                raise

    def delete_by_doc_id(
        self, doc_id: str, *, idempotency_key: str | None = None
    ) -> None:
        # Registered indexing cleanup must retain source and dataset isolation.
        # The authorization callback validates doc_id; source/dataset are bound
        # by the registration, rather than supplied by document content.
        payload = self._owned_payload(doc_id)
        return self.delete_by_filter(
            {
                "must": [
                    {"key": key, "match": {"value": value}}
                    for key, value in payload.items()
                ]
            },
            idempotency_key=idempotency_key,
        )

    def _owned_payload(self, doc_id: str) -> dict[str, Any]:
        # Set by the source-scoped factory; never derive ownership from sink reads.
        payload = {**self.ownership, "doc_id": doc_id}
        self.authorize(payload)
        return payload


__all__ = ["create_qdrant_writer", "RegisteredVectorWriter"]
