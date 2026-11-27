"""Verify the opt-in E2E targets current service APIs and always cleans its source."""

import importlib.util
from pathlib import Path

import pytest


@pytest.mark.parametrize("failed", [False, True])
def test_service_api_contract_and_cleanup(monkeypatch, failed):
    spec = importlib.util.spec_from_file_location(
        "current_text_e2e",
        Path(__file__).parent / "integration/test_direct_text_ingestion.py",
    )
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    for name, value in {
        "RAWKI_INTEGRATION_SERVICE_TOKEN": "test-service-jwt",
        "RAWKI_INTEGRATION_TEXT_INGEST_DATASET_ID": "disposable",
        "RAWKI_INTEGRATION_QDRANT_COLLECTION": "physical-disposable",
        "RAWKI_INTEGRATION_BACKEND_URL": "http://backend.test",
    }.items():
        monkeypatch.setenv(name, value)
    calls, state = [], {}

    def request(method, url, *, payload=None, headers=None):
        assert headers["Authorization"] == "Bearer test-service-jwt"
        assert url.startswith("http://backend.test/v1/")
        calls.append((method, url, payload, headers))
        if method in {"POST", "PUT"}:
            assert url == "http://backend.test/v1/documents" + (
                "/document" if method == "PUT" else ""
            )
            state.update(payload)
            state["source"] = "replacement" if method == "PUT" else "source"
            return 202, {
                "document": {"document_id": "document"},
                "pipeline": {
                    "task_id": "task",
                    "source_id": state["source"],
                    "job_id": "job",
                },
            }
        if method == "DELETE":
            state["cleaned"] = True
            return 200, {}
        if "limit=" in url:
            return 200, {}
        return 200, {
            "task": {
                "status": "failed" if failed else "completed",
                "dataset_id": "disposable",
                "jobs": [
                    {
                        "job_id": "job",
                        "source_id": state["source"],
                        "temporal_workflow_id": "ingest-source-" + state["source"],
                        "index_status": "ready",
                    }
                ],
            }
        }

    def points(collection, source, *, conditions=()):
        assert collection == "physical-disposable"
        if (
            state.get("cleaned")
            or conditions == ({"key": "metadata.year", "match": {"value": "2025"}},)
            or source != state["source"]
        ):
            return []
        return [
            {
                "id": "point",
                "payload": {
                    "metadata": state["metadata"],
                    "content": state["text"].rstrip(),
                    "managed_document_id": "document",
                },
            }
        ]

    monkeypatch.setattr(module, "_json_request", request)
    monkeypatch.setattr(module, "_source_points", points)
    if failed:
        with pytest.raises(AssertionError):
            module.test_direct_text_reaches_qdrant_and_ready_state(pytest.fail)
    else:
        module.test_direct_text_reaches_qdrant_and_ready_state(pytest.fail)
    assert state["cleaned"]
    posts = [call for call in calls if call[0] == "POST"]
    assert len(posts) == 2
    assert posts[0][2:] == posts[1][2:]
    assert sum(call[0] == "PUT" for call in calls) == (0 if failed else 1)
    assert calls[-1][0:2] == ("DELETE", "http://backend.test/v1/documents/document")


def test_managed_text_converter_output_reaches_the_indexer(tmp_path, monkeypatch):
    from hawki_artifact_store.local import LocalArtifactStore
    from hawki_converter_worker.application.dependencies import ConversionDependencies
    from hawki_converter_worker.application.source_conversion import (
        execute_source_conversion,
    )
    from hawki_converter_worker.settings import ConverterSettings
    from hawki_indexer_worker.indexing.artifact_documents import (
        ArtifactPreparationContext,
        prepare_artifact_batch,
    )
    from hawki_indexer_worker.indexing.chunking import prepare_documents
    from hawki_rag_contracts.pipeline.ingestion import ConvertActivityInput

    monkeypatch.setenv("RAG_WORKER_CALLBACK_SECRET", "unit-test")
    text = "| Page |\n\nCafé and design.\n"
    upload = tmp_path / "text.md"
    upload.write_text(text, encoding="utf-8")
    wire = {
        "source_id": "source-test",
        "source_url": "upload://text.md",
        "dataset_id": "dataset-test",
        "managed_document_id": "adoc_test",
        "managed_upload_protocol": 2,
        "upload": {"local_path": str(upload), "extension": "md"},
        "upload_metadata": {"year": 2026, "topics": ["design"]},
        "storage": {"shared_root": str(tmp_path)},
        "raw_output_path": str(tmp_path / "raw"),
        "markdown_output_path": str(tmp_path / "markdown"),
    }

    def unexpected_extractor(_config):
        pytest.fail("Inline Markdown uses the converter worker's staged artifact path")

    converted = execute_source_conversion(
        ConvertActivityInput(
            workflow_input=wire,
            scrape_result={"source_id": wire["source_id"], "status": "success"},
        ),
        settings=ConverterSettings.from_env(),
        dependencies=ConversionDependencies(
            artifact_store_factory=LocalArtifactStore,
            direct_extract_client_factory=unexpected_extractor,
            external_converter_client_factory=unexpected_extractor,
        ),
    )
    assert converted.status == "success"
    assert converted.markdown_files_created == 1
    artifact = converted.artifacts[0]
    assert Path(artifact.uri).read_text(encoding="utf-8") == text
    context = ArtifactPreparationContext(
        workflow_input=wire,
        options={},
        markdown_dir=converted.markdown_dir,
        artifact_store=LocalArtifactStore(tmp_path),
        artifacts_by_path={},
    )
    indexed = prepare_artifact_batch([artifact.uri], context)
    chunks, _ = prepare_documents(
        indexed.documents, chunk_chars=1200, chunk_overlap=0, default_job_id="job-test"
    )
    assert len(chunks) == 1
    assert chunks[0]["content"] == text.strip()
    assert chunks[0]["payload"]["metadata"] == wire["upload_metadata"]
    assert chunks[0]["payload"]["managed_document_id"] == "adoc_test"
