from __future__ import annotations

import hashlib
import logging
from types import SimpleNamespace

from hawki_indexer_worker.indexing.chunking import prepare_documents
from hawki_indexer_worker.indexing.incremental import plan_incremental_ingest
from hawki_rag_contracts.pipeline.identity import document_id


def test_direct_text_reingestion_replaces_legacy_url_identity_by_source_id() -> None:
    source_id = "source_stable_external_a"
    artifact_doc_id = document_id(source_id, "document.md")
    new_records, new_stats = prepare_documents(
        [
            SimpleNamespace(
                id=artifact_doc_id,
                text="Unchanged text",
                payload={
                    "dataset_id": "dataset-a",
                    "source_id": source_id,
                    "external_document_id": "A",
                    "source_url": "https://example.test/new",
                    "source_format": "markdown",
                    "ingestion_mode": "direct_text",
                },
            )
        ],
        chunk_chars=1200,
        chunk_overlap=0,
        default_job_id="job-a",
    )
    legacy_identity = "url:https://example.test/old"
    legacy_doc_id = "doc_" + hashlib.sha256(legacy_identity.encode()).hexdigest()[:40]
    legacy_payload = {
        **new_records[0]["payload"],
        "doc_id": legacy_doc_id,
        "source_identity": legacy_identity,
        "source_url": "https://example.test/old",
        "page_url": "https://example.test/old",
        "canonical_url": "https://example.test/old",
    }

    class LegacyQdrant:
        def __init__(self) -> None:
            self.filters: list[dict[str, object]] = []

        def find_points_by_payload(
            self, filters: dict[str, object], *, limit: int = 1
        ) -> list[dict[str, object]]:
            del limit
            self.filters.append(filters)
            if all(legacy_payload.get(key) == value for key, value in filters.items()):
                return [{"payload": legacy_payload}]
            return []

    qdrant = LegacyQdrant()
    plan = plan_incremental_ingest(
        new_records,
        doc_stats=new_stats,
        qdrant=qdrant,
        collection="direct-documents",
        operation_id="replace-legacy-url-identity",
        logger_obj=logging.getLogger("test_direct_legacy_url_identity"),
    )

    assert plan.changed_doc_ids == {artifact_doc_id}
    assert plan.replace_doc_ids == {artifact_doc_id, legacy_doc_id}
    assert plan.replace_doc_ids_by_doc == {
        artifact_doc_id: {artifact_doc_id, legacy_doc_id}
    }
    assert {"source_id": source_id} in qdrant.filters
    assert all(
        not set(filters).intersection(
            {"canonical_url", "page_url", "original_url", "url", "source_url"}
        )
        for filters in qdrant.filters
    )
