"""Artifact identity survives normalization, retries, and incremental writes."""

from copy import deepcopy
from pathlib import Path
from typing import Any

import pytest

from hawki_artifact_store.local import LocalArtifactStore
from hawki_rag_contracts.pipeline.identity import document_id
from hawki_indexer_worker.domain.errors import (
    DocumentCompletionError,
    IndexingValidationError,
)
from hawki_indexer_worker.indexing.artifact_documents import (
    ArtifactPreparationContext,
    prepare_artifact_batch,
)
from hawki_indexer_worker.indexing.chunking import prepare_documents
from hawki_indexer_worker.indexing.dependencies import IngestWorkflowDependencies
from hawki_indexer_worker.indexing.graph_settings import GraphIngestSettings
from hawki_indexer_worker.indexing.orchestration import ingest_documents
from hawki_indexer_worker.indexing.page_state import (
    QdrantPageState,
    DOCUMENT_COMPLETE_FIELD,
)
from hawki_indexer_worker.indexing.request import IndexRequest
from hawki_indexer_worker.indexing.vector_prepare import build_points


class Embeddings:
    def embed(self, text: str) -> list[float]:
        return [float(len(text)), 1.0]


def artifacts(tmp_path: Path, files: dict[str, str], source: str = "source-a"):
    root = tmp_path / source / "markdown"
    paths = []
    for relative, content in files.items():
        path = root / relative
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding="utf-8")
        paths.append(str(path))
    return prepare_artifact_batch(
        paths,
        ArtifactPreparationContext(
            workflow_input={
                "source_id": source,
                "source_url": "https://hawk.de",
                "dataset_id": "dataset-a",
            },
            options={},
            markdown_dir=str(root),
            artifact_store=LocalArtifactStore(tmp_path),
            artifacts_by_path={},
        ),
    )


@pytest.mark.parametrize("second_text", ["HAWKI uses Neo4j.", "HAWKI uses Qdrant."])
def test_shared_seed_url_preserves_artifact_ids_through_point_creation(
    tmp_path, second_text
):
    files = {
        "page-a/output/chunks/00001.md": "HAWKI uses Qdrant.",
        "page-b/output/chunks/00001.md": second_text,
    }
    prepared = artifacts(tmp_path, files)
    chunks, _stats = prepare_documents(
        prepared.documents, chunk_chars=1200, chunk_overlap=0, default_job_id="job-1"
    )
    points, _size, failures = build_points(chunks, Embeddings())
    expected = {document_id("source-a", path) for path in files}

    assert {record["doc_id"] for record in chunks} == expected
    assert {record["document_id"] for record in prepared.manifest_records} == expected
    assert len({point["id"] for point in points}) == 2
    assert not failures
    for point in points:
        payload = point["payload"]
        assert payload["document_id"] == payload["doc_id"]
        assert payload["source_identity"] == f"doc:{payload['doc_id']}"


# These fakes model point-key overwrite and document-scoped graph contributions;
# all preparation, lookup, replacement, and completion logic stays real.


class Vectors:
    collection = "test-artifacts"

    def __init__(self):
        self.points: dict[str, dict[str, Any]] = {}
        self.writes = []
        self.filters = []

    def set_collection(self, collection):
        self.collection = collection

    def ensure_collection(self, *_args, **_kwargs):
        self.writes.append("ensure")

    def find_points_by_payload(self, filters, *, limit=1):
        self.filters.append(filters)
        return [
            deepcopy(point)
            for point in self.points.values()
            if all(point["payload"].get(k) == v for k, v in filters.items())
        ][:limit]

    def delete_by_doc_id(self, doc_id, **_kwargs):
        self.writes.append(("delete", doc_id))
        self.points = {
            key: point
            for key, point in self.points.items()
            if point["payload"]["doc_id"] != doc_id
        }

    def upsert_points(self, points, **_kwargs):
        self.writes.append("upsert")
        self.points.update({point["id"]: deepcopy(point) for point in points})

    def set_payload(self, ids, payload, **_kwargs):
        self.writes.append("payload")
        for point_id in ids:
            self.points[point_id]["payload"].update(payload)


class Graph:
    def __init__(self):
        self.documents = {}
        self.deletions = []
        self.fail_write = False

    def delete_by_doc_id(self, doc_id, **_kwargs):
        self.deletions.append(doc_id)
        self.documents.pop(doc_id, None)

    def upsert_triplets(self, triplets, *, doc_id, **_kwargs):
        if self.fail_write:
            raise RuntimeError("graph store unavailable")
        self.documents[doc_id] = list(triplets)

    def close(self):
        return None


class Extractor:
    def __init__(self):
        self.fail = False

    def extract_triplets(self, _text, _engine, *, chunks, **_kwargs):
        if self.fail:
            raise RuntimeError("extraction unavailable")
        # Return names literally present in the text so the real graph filter runs.
        target = "Neo4j" if "Neo4j" in " ".join(chunks) else "Qdrant"
        return [("HAWKI", "uses", target)]


class Pipeline:
    def __init__(self):
        self.vectors = Vectors()
        self.graph = Graph()
        self.extractor = Extractor()
        self.dependencies = IngestWorkflowDependencies(
            vector_writer_factory=lambda: self.vectors,
            graph_writer_factory=lambda **_kwargs: self.graph,
            page_state_factory=QdrantPageState,
            graph_settings_loader=lambda: GraphIngestSettings(
                graph_debug=False,
                graph_perf_log=False,
                graph_doc_timeout_s=0,
                graph_doc_max_chars=0,
                graph_doc_max_chunks=0,
            ),
        )

    def run(self, documents, *, retry=False):
        return ingest_documents(
            IndexRequest(
                docs=documents,
                provider="fake",
                collection=self.vectors.collection,
                dataset_id="dataset-a",
                neo4j_namespace="graph-a",
                graph=True,
                chunk_chars=80,
                chunk_overlap=0,
                job_id="retry" if retry else "first",
            ),
            rag_service=self.extractor,
            get_provider=lambda _name: Embeddings(),
            dependencies=self.dependencies,
        )


def test_multibatch_retry_update_and_shorter_document_preserve_other_files(tmp_path):
    pipeline = Pipeline()
    first = artifacts(tmp_path, {"a/page.md": "HAWKI uses Qdrant. " * 30})
    second = artifacts(tmp_path, {"b/page.md": "HAWKI uses Neo4j. " * 20})
    first_id, second_id = first.documents[0].id, second.documents[0].id
    pipeline.run(first.documents)
    pipeline.run(second.documents)
    assert set(pipeline.graph.documents) == {first_id, second_id}
    assert {p["payload"]["doc_id"] for p in pipeline.vectors.points.values()} == {
        first_id,
        second_id,
    }
    assert len(pipeline.vectors.points) > 2

    previous = deepcopy(pipeline.vectors.points)
    writes = len(pipeline.vectors.writes)
    pipeline.run(first.documents + second.documents, retry=True)
    assert pipeline.vectors.points == previous
    assert len(pipeline.vectors.writes) == writes

    second_points = {
        key: value
        for key, value in previous.items()
        if value["payload"]["doc_id"] == second_id
    }
    second_graph = deepcopy(pipeline.graph.documents[second_id])
    changed = artifacts(tmp_path, {"a/page.md": "HAWKI uses Neo4j."})
    assert changed.documents[0].id == first_id
    pipeline.run(changed.documents)
    assert {
        key: value
        for key, value in pipeline.vectors.points.items()
        if value["payload"]["doc_id"] == second_id
    } == second_points
    assert (
        sum(
            p["payload"]["doc_id"] == first_id for p in pipeline.vectors.points.values()
        )
        == 1
    )
    assert pipeline.graph.documents[second_id] == second_graph
    assert pipeline.graph.documents[first_id] == [("HAWKI", "uses", "Neo4j")]
    assert pipeline.graph.deletions == [first_id]
    assert ("delete", second_id) not in pipeline.vectors.writes


def test_same_relative_path_in_two_sources_has_separate_ownership(tmp_path):
    pipeline = Pipeline()
    for source in ("source-a", "source-b"):
        prepared = artifacts(tmp_path, {"same.md": "HAWKI uses Qdrant."}, source)
        pipeline.run(prepared.documents)
    assert len(pipeline.vectors.points) == 2
    assert len(pipeline.graph.documents) == 2
    assert not pipeline.graph.deletions


def test_legacy_url_record_cannot_authorize_replacement(tmp_path):
    pipeline = Pipeline()
    prepared = artifacts(tmp_path, {"page.md": "HAWKI uses Qdrant."})
    legacy = {
        "id": "legacy-point",
        "payload": {
            **prepared.documents[0].payload,
            "doc_id": "doc_legacy_url_collision",
            "source_identity": "url:https://hawk.de",
            "canonical_url": "https://hawk.de",
            "page_url": "https://hawk.de",
        },
    }
    pipeline.vectors.points["legacy-point"] = deepcopy(legacy)
    pipeline.graph.documents["doc_legacy_url_collision"] = [("old", "uses", "data")]
    pipeline.run(prepared.documents)
    assert pipeline.vectors.points["legacy-point"] == legacy
    assert "doc_legacy_url_collision" in pipeline.graph.documents
    assert len(pipeline.vectors.points) == 2
    assert all(
        not any("url" in key for key in filters) for filters in pipeline.vectors.filters
    )


@pytest.mark.parametrize("failure", ["extraction", "write"])
def test_retry_after_vectors_recovers_graph_without_a_completion_marker(
    tmp_path, failure
):
    pipeline = Pipeline()
    prepared = artifacts(tmp_path, {"page.md": "HAWKI uses Qdrant."})
    pipeline.extractor.fail = failure == "extraction"
    pipeline.graph.fail_write = failure == "write"
    with pytest.raises((DocumentCompletionError, RuntimeError)):
        pipeline.run(prepared.documents)
    ids = set(pipeline.vectors.points)
    assert ids
    assert not any(
        p["payload"].get(DOCUMENT_COMPLETE_FIELD)
        for p in pipeline.vectors.points.values()
    )
    pipeline.extractor.fail = False
    pipeline.graph.fail_write = False
    # A normal retry must recover; no forced reprocess flag is needed.
    pipeline.run(prepared.documents, retry=True)
    assert set(pipeline.vectors.points) == ids
    assert pipeline.graph.documents[prepared.documents[0].id] == [
        ("HAWKI", "uses", "Qdrant")
    ]
    assert all(
        p["payload"][DOCUMENT_COMPLETE_FIELD] for p in pipeline.vectors.points.values()
    )


@pytest.mark.parametrize(
    "field,value",
    [
        ("source_id", ""),
        ("source_id", None),
        ("relative_path", ""),
        ("relative_path", "../escape.md"),
        ("relative_path", "/absolute.md"),
        ("document_id", "wrong-id"),
        ("doc_id", "wrong-id"),
    ],
)
def test_invalid_artifact_identity_aborts_before_writes(tmp_path, field, value):
    pipeline = Pipeline()
    prepared = artifacts(tmp_path, {"page.md": "HAWKI uses Qdrant."})
    prepared.documents[0].payload[field] = value
    with pytest.raises(IndexingValidationError):
        pipeline.run(prepared.documents)
    assert not pipeline.vectors.writes
    assert not pipeline.graph.documents


def test_duplicate_chunks_abort_even_when_document_is_unchanged(tmp_path):
    pipeline = Pipeline()
    prepared = artifacts(tmp_path, {"page.md": "HAWKI uses Qdrant."})
    pipeline.run(prepared.documents)
    previous = deepcopy(pipeline.vectors.points)
    writes = len(pipeline.vectors.writes)
    with pytest.raises(IndexingValidationError, match="Duplicate point ID"):
        pipeline.run(prepared.documents * 2)
    assert pipeline.vectors.points == previous
    assert len(pipeline.vectors.writes) == writes
    assert not pipeline.graph.deletions


@pytest.mark.parametrize("field", ["source_id", "relative_path"])
def test_missing_artifact_identity_field_does_not_fall_back_to_url(tmp_path, field):
    pipeline = Pipeline()
    prepared = artifacts(tmp_path, {"page.md": "HAWKI uses Qdrant."})
    del prepared.documents[0].payload[field]
    with pytest.raises(IndexingValidationError):
        pipeline.run(prepared.documents)
    assert not pipeline.vectors.writes


def test_urls_and_job_ids_are_descriptive_for_artifacts(tmp_path):
    prepared = artifacts(tmp_path, {"page.md": "HAWKI uses Qdrant."})
    before, _ = prepare_documents(
        prepared.documents, chunk_chars=1200, chunk_overlap=0, default_job_id="job-a"
    )
    prepared.documents[0].payload.update(
        {
            "source_url": "https://example.test/moved",
            "page_url": "https://example.test/page",
            "canonical_url": "https://example.test/canonical",
            "job_id": "job-b",
        }
    )
    after, _ = prepare_documents(
        prepared.documents, chunk_chars=1200, chunk_overlap=0, default_job_id="job-b"
    )
    before_points, _, _ = build_points(before, Embeddings())
    after_points, _, _ = build_points(after, Embeddings())
    assert [p["id"] for p in before_points] == [p["id"] for p in after_points]
    assert (
        before[0]["payload"]["source_identity"]
        == after[0]["payload"]["source_identity"]
    )


def test_embedding_failure_preserves_previous_complete_artifact(tmp_path):
    from hawki_indexer_worker.domain.errors import EmbeddingError

    pipeline = Pipeline()
    original = artifacts(tmp_path, {"page.md": "HAWKI uses Qdrant."})
    pipeline.run(original.documents)
    previous = deepcopy(pipeline.vectors.points)
    graph = deepcopy(pipeline.graph.documents)
    writes = len(pipeline.vectors.writes)
    changed = artifacts(tmp_path, {"page.md": "HAWKI uses Neo4j. " * 30})

    class BrokenEmbeddings(Embeddings):
        def embed(self, text):
            raise RuntimeError("embedding unavailable")

    with pytest.raises(EmbeddingError):
        ingest_documents(
            IndexRequest(docs=changed.documents, chunk_chars=80, chunk_overlap=0),
            rag_service=pipeline.extractor,
            get_provider=lambda _: BrokenEmbeddings(),
            dependencies=pipeline.dependencies,
        )
    assert pipeline.vectors.points == previous
    assert pipeline.graph.documents == graph
    assert len(pipeline.vectors.writes) == writes


def test_vector_commit_rejects_duplicate_ids_before_replacement(tmp_path):
    import logging
    from hawki_indexer_worker.indexing.vector_commit import commit_vector_points

    prepared = artifacts(tmp_path, {"page.md": "HAWKI uses Qdrant."})
    records, stats = prepare_documents(
        prepared.documents, chunk_chars=1200, chunk_overlap=0, default_job_id=None
    )
    vectors = Vectors()
    with pytest.raises(IndexingValidationError, match="Duplicate point ID"):
        commit_vector_points(
            body=IndexRequest(docs=[]),
            chunk_records=records * 2,
            doc_stats=stats,
            provider=Embeddings(),
            qdrant=vectors,
            batch_size=1,
            job_id=None,
            operation_id=None,
            replace_doc_ids={prepared.documents[0].id},
            logger_obj=logging.getLogger(__name__),
        )
    assert not vectors.writes


def test_registry_result_with_wrong_owner_cannot_authorize_deletion(tmp_path):
    from hawki_indexer_worker.indexing.incremental import plan_incremental_ingest
    import logging

    prepared = artifacts(tmp_path, {"page.md": "HAWKI uses Qdrant."})
    records, stats = prepare_documents(
        prepared.documents, chunk_chars=1200, chunk_overlap=0, default_job_id=None
    )

    class WrongOwner:
        def find_by_source_identity(self, **_kwargs):
            return {
                **records[0]["payload"],
                "doc_id": "another-document",
                "content_hash": "changed",
            }

    plan = plan_incremental_ingest(
        records,
        doc_stats=stats,
        qdrant=Vectors(),
        collection="test-artifacts",
        page_registry=WrongOwner(),
        operation_id=None,
        logger_obj=logging.getLogger(__name__),
    )
    assert plan.new_doc_ids == {prepared.documents[0].id}
    assert not plan.replace_doc_ids
    assert not plan.replace_doc_ids_by_doc
