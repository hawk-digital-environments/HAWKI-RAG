"""Architecture-boundary scenarios that keep adapters explicit and injectable."""

from __future__ import annotations

import importlib
import tempfile
import tomllib
from pathlib import Path

ROOT = next(
    parent
    for parent in Path(__file__).resolve().parents
    if (parent / "uv.lock").is_file()
)


def test_canonical_lightrag_doc_status_storage_import_path_resolves() -> None:
    module = importlib.import_module(
        "hawki_indexer_worker.adapters.raganything.lightrag_status_store"
    )

    assert module.ChunkedJsonDocStatusStorage.__name__ == "ChunkedJsonDocStatusStorage"


def test_raganything_runtime_requirements_include_mineru_pipeline_extra() -> None:
    workspace = tomllib.loads((ROOT / "pyproject.toml").read_text(encoding="utf-8"))
    indexer = tomllib.loads(
        (ROOT / "services" / "hawki_indexer_worker" / "pyproject.toml").read_text(
            encoding="utf-8"
        )
    )

    assert "raganything[all]==1.3.1" in indexer["project"]["dependencies"]
    assert workspace["tool"]["uv"]["override-dependencies"] == [
        "mineru[pipeline]==3.4.4"
    ]


def test_doc_status_chunk_helpers_plan_chunks_and_duplicate_counts() -> None:
    from hawki_indexer_worker.adapters.raganything.doc_status import (
        annotate_duplicate_skip_metadata,
        chunk_item_dicts,
        count_status_records,
        merge_chunk_payloads,
        sort_chunk_files,
    )

    paths = ["kv_store_doc_status_chunk_10.json", "kv_store_doc_status_chunk_2.json"]
    assert sort_chunk_files(paths) == [
        "kv_store_doc_status_chunk_2.json",
        "kv_store_doc_status_chunk_10.json",
    ]
    merged = merge_chunk_payloads(paths, lambda path: {"doc": path})
    assert merged == {"doc": "kv_store_doc_status_chunk_10.json"}
    assert chunk_item_dicts([("a", 1), ("b", 2), ("c", 3)], max_entries=2) == [
        {"a": 1, "b": 2},
        {"c": 3},
    ]

    duplicate = {
        "status": "FAILED",
        "error_msg": "Content already exists. Original doc_id: doc-a",
    }
    annotate_duplicate_skip_metadata("doc-b", duplicate, failed_status_value="FAILED")
    assert duplicate["metadata"]["effective_status"] == "skipped"

    counts = count_status_records(
        [
            ("doc-a", {"status": "PROCESSED"}),
            ("doc-b", duplicate),
            ("dup-c", {"status": "FAILED"}),
        ],
        status_values=["PROCESSED", "FAILED"],
        failed_status_value="FAILED",
        duplicate_count_key="skipped_duplicates",
    )
    assert counts == {"PROCESSED": 1, "FAILED": 0, "skipped_duplicates": 2}


def test_graph_document_preparation_trims_chunks_and_keeps_source() -> None:
    from hawki_indexer_worker.indexing.graph_documents import prepare_graph_document

    prepared = prepare_graph_document(
        "doc-a",
        [
            {"content": "abcd", "payload": {"file_path": "/tmp/doc.md"}},
            {"content": "efghij"},
            {"content": "klmn"},
            {"content": "  "},
        ],
        max_chunks=2,
        max_chars=7,
    )

    assert prepared.chunk_texts == ["abcd", "efg"]
    assert prepared.original_chunk_count == 3
    assert prepared.original_chars == 14
    assert prepared.total_chars == 7
    assert prepared.file_path == "/tmp/doc.md"
    assert prepared.was_trimmed is True


def test_graph_document_preparation_collects_original_image_paths() -> None:
    from hawki_indexer_worker.indexing.graph_documents import prepare_graph_document

    prepared = prepare_graph_document(
        "image-doc",
        [
            {
                "content": "Temporal Workflow routes conversion activities to the converter worker.",
                "payload": {
                    "file_path": "/tmp/converted.md",
                    "original_path": "/tmp/temporal-workflow.png",
                    "source_file": "/tmp/source-file.png",
                    "image_path": "/tmp/temporal-workflow.png",
                    "images": ["/tmp/flow.jpg", "/tmp/readme.md"],
                },
            }
        ],
        max_chunks=0,
        max_chars=0,
    )

    assert prepared.file_path == "/tmp/converted.md"
    assert prepared.image_paths == [
        "/tmp/temporal-workflow.png",
        "/tmp/source-file.png",
        "/tmp/flow.jpg",
    ]


def test_graph_content_list_includes_existing_image_blocks() -> None:
    from hawki_indexer_worker.adapters.raganything.extraction_runtime import (
        graph_content_list_from_input,
    )

    with tempfile.TemporaryDirectory() as tmp:
        image_path = Path(tmp) / "temporal-workflow.png"
        image_path.write_bytes(b"not-a-real-png-but-existing")

        content = graph_content_list_from_input(
            "",
            [" OCR text from image "],
            image_paths=[
                str(image_path),
                str(Path(tmp) / "missing.png"),
                "relative.png",
            ],
        )

    assert content[0] == {"type": "text", "text": "OCR text from image", "page_idx": 0}
    assert content[1]["type"] == "image"
    assert content[1]["img_path"] == str(image_path)
    assert content[1]["page_idx"] == 1


def test_llm_triplet_fallback_parses_strict_json_response() -> None:
    from hawki_indexer_worker.adapters.raganything.llm_fallback import (
        parse_llm_triplet_response,
    )

    response = """
    ```json
    {
      "triplets": [
        {"subject": "Temporal Workflow", "relation": "routes", "object": "converter worker"},
        {"subject": "Temporal Workflow", "relation": "routes", "object": "converter worker"},
        {"subject": "[]", "relation": "mentions", "object": "noise"}
      ]
    }
    ```
    """

    assert parse_llm_triplet_response(response) == [
        ("Temporal Workflow", "routes", "converter worker")
    ]


def test_llm_triplet_fallback_recovers_complete_objects_from_truncated_response() -> (
    None
):
    from hawki_indexer_worker.adapters.raganything.llm_fallback import (
        parse_llm_triplet_response,
    )

    response = """
    Here is the JSON:
    ```
    {"triplets":[
      {"subject":"Dead-letter Queue","relation":"receives","object":"failed jobs"},
      {"subject":"Retry Exchange","relation":"routes","object":"retry queues"},
      {"subject":"Incomplete","relation":"breaks","object":"
    """

    assert parse_llm_triplet_response(response) == [
        ("Dead-letter Queue", "receives", "failed jobs"),
        ("Retry Exchange", "routes", "retry queues"),
    ]


def test_triplet_extractor_uses_llm_fallback_when_raganything_returns_empty() -> None:
    from hawki_indexer_worker.adapters.raganything.extraction import (
        extract_triplets_with_graph_service,
    )

    class EmptyGraphService:
        def __init__(self) -> None:
            self.kwargs: dict[str, object] = {}

        def extract_triplets(
            self, text: str, **kwargs: object
        ) -> list[tuple[str, str, str]]:
            self.kwargs = kwargs
            return []

    class Provider:
        def __init__(self) -> None:
            self.messages: list[dict[str, str]] = []

        def chat(
            self,
            system: str,
            messages: list[dict[str, str]],
            *,
            temperature: float | None = None,
        ) -> str:
            self.messages = messages
            return (
                '{"triplets":[{"subject":"Temporal Workflow",'
                '"relation":"routes","object":"converter worker"}]}'
            )

    graph_service = EmptyGraphService()
    provider = Provider()

    triplets = extract_triplets_with_graph_service(
        graph_service,  # type: ignore[arg-type]
        "",
        "raganything",
        provider=provider,
        chunks=[
            "Temporal Workflow routes conversion activities to the converter worker."
        ],
        doc_id="image-doc",
        file_path="/tmp/converted.md",
        image_paths=["/tmp/temporal-workflow.png"],
        neo4j_database="neo4j",
        graph_perf_log=False,
    )

    assert triplets == [("Temporal Workflow", "routes", "converter worker")]
    assert graph_service.kwargs["image_paths"] == ["/tmp/temporal-workflow.png"]
    assert "/tmp/temporal-workflow.png" in provider.messages[0]["content"]
