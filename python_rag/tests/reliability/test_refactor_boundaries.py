"""Architecture-boundary scenarios that keep adapters injectable and optional imports explicit."""

from __future__ import annotations

import logging
import importlib
import sys
import tempfile
import types
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))


def _install_optional_dependency_stubs() -> None:
    if "neo4j" not in sys.modules:
        neo4j_module = types.ModuleType("neo4j")

        class Neo4jError(Exception):
            pass

        class GraphDatabase:
            @staticmethod
            def driver(*args: object, **kwargs: object) -> object:
                raise RuntimeError("GraphDatabase.driver should not be called in refactor boundary tests")

        neo4j_module.GraphDatabase = GraphDatabase
        neo4j_module.exceptions = types.SimpleNamespace(Neo4jError=Neo4jError)
        sys.modules["neo4j"] = neo4j_module


_install_optional_dependency_stubs()


def test_qdrant_client_ops_capture_gateway_and_limit_policy() -> None:
    from infrastructure.vectorstore.qdrant_client_ops import (
        gateway_supports_operation_id,
        resolve_per_collection_limit,
        resolve_selected_collection,
    )

    class NewGateway:
        def upsert(self, points: list[dict[str, object]], *, timeout: float, operation_id: str | None = None) -> None:
            return None

    class LegacyGateway:
        def upsert(self, points: list[dict[str, object]], *, timeout: float) -> None:
            return None

    assert gateway_supports_operation_id(NewGateway(), "upsert") is True
    assert gateway_supports_operation_id(LegacyGateway(), "upsert") is False
    assert resolve_per_collection_limit(4, 10) == 4
    assert resolve_per_collection_limit(0, 10) == 10
    assert resolve_selected_collection("", lambda: "picked") == "picked"
    assert resolve_selected_collection("explicit", lambda: "picked") == "explicit"


def test_optional_import_helper_reports_missing_runtime_dependencies() -> None:
    from common.optional_imports import import_optional_module, import_required_module

    assert import_optional_module("sys") is sys
    assert import_optional_module("__hawki_missing_dependency__") is None

    try:
        import_required_module(
            "__hawki_missing_dependency__",
            install_hint="install the project requirements",
        )
    except RuntimeError as exc:
        assert "install the project requirements" in str(exc)
    else:
        raise AssertionError("missing required dependency should raise RuntimeError")


def test_canonical_lightrag_doc_status_storage_import_path_resolves() -> None:
    module = importlib.import_module("infrastructure.raganything.lightrag_chunked_doc_status_storage")

    assert module.ChunkedJsonDocStatusStorage.__name__ == "ChunkedJsonDocStatusStorage"


def test_raganything_runtime_requirements_include_mineru_core_extra() -> None:
    requirements = (ROOT / "requirements.txt").read_text(encoding="utf-8")

    assert "raganything[all]" in requirements
    assert "mineru[core]" in requirements


def test_doc_status_chunk_helpers_plan_chunks_and_duplicate_counts() -> None:
    from infrastructure.raganything.doc_status_chunks import (
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

    duplicate = {"status": "FAILED", "error_msg": "Content already exists. Original doc_id: doc-a"}
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
    from application.workflows.ingest.graph_documents import prepare_graph_document

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
    from application.workflows.ingest.graph_documents import prepare_graph_document

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
    assert prepared.image_paths == ["/tmp/temporal-workflow.png", "/tmp/source-file.png", "/tmp/flow.jpg"]


def test_graph_content_list_includes_existing_image_blocks() -> None:
    from infrastructure.raganything.raganything_extract import graph_content_list_from_input

    with tempfile.TemporaryDirectory() as tmp:
        image_path = Path(tmp) / "temporal-workflow.png"
        image_path.write_bytes(b"not-a-real-png-but-existing")

        content = graph_content_list_from_input(
            "",
            [" OCR text from image "],
            image_paths=[str(image_path), str(Path(tmp) / "missing.png"), "relative.png"],
        )

    assert content[0] == {"type": "text", "text": "OCR text from image", "page_idx": 0}
    assert content[1]["type"] == "image"
    assert content[1]["img_path"] == str(image_path)
    assert content[1]["page_idx"] == 1


def test_llm_triplet_fallback_parses_strict_json_response() -> None:
    from infrastructure.raganything.llm_triplet_fallback import parse_llm_triplet_response

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


def test_llm_triplet_fallback_recovers_complete_objects_from_truncated_response() -> None:
    from infrastructure.raganything.llm_triplet_fallback import parse_llm_triplet_response

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
    from infrastructure.raganything.extraction import extract_triplets_with_graph_service

    class EmptyGraphService:
        def __init__(self) -> None:
            self.kwargs: dict[str, object] = {}

        def extract_triplets(self, text: str, **kwargs: object) -> list[tuple[str, str, str]]:
            self.kwargs = kwargs
            return []

    class Provider:
        def __init__(self) -> None:
            self.messages: list[dict[str, str]] = []

        def chat(self, system: str, messages: list[dict[str, str]], *, temperature: float | None = None) -> str:
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
        chunks=["Temporal Workflow routes conversion activities to the converter worker."],
        doc_id="image-doc",
        file_path="/tmp/converted.md",
        image_paths=["/tmp/temporal-workflow.png"],
        neo4j_database="neo4j",
        graph_perf_log=False,
    )

    assert triplets == [("Temporal Workflow", "routes", "converter worker")]
    assert graph_service.kwargs["image_paths"] == ["/tmp/temporal-workflow.png"]
    assert "/tmp/temporal-workflow.png" in provider.messages[0]["content"]


def test_text_helper_modules_preserve_term_tag_and_chunk_rules() -> None:
    from common.text_chunking import split_text_into_chunks
    from common.text_tags import fallback_tags, flatten_keywords, normalize_tags
    from common.text_terms import STOPWORDS, extract_terms

    assert len(STOPWORDS) > 1800
    assert "die" in STOPWORDS
    assert "; german stopwords" not in STOPWORDS
    assert extract_terms("Wooden trains and Teddy-Bears")[:2] == ["wooden", "trains"]
    assert extract_terms("diese Universität") == ["universität"]
    assert flatten_keywords("Keywords: 1. Wooden toys; 2. Teddy bears") == [
        "Wooden toys",
        "Teddy bears",
    ]
    assert normalize_tags(["Wooden-Toys", "wooden toys", "A"], limit=3) == ["wooden toys"]
    assert fallback_tags("train train bear bear table", limit=2) == ["train", "bear"]
    assert split_text_into_chunks("para one\n\npara two\n\npara three", target=13, overlap=0) == [
        "para one",
        "para two",
        "para three",
    ]


def test_neo4j_client_ops_reuse_executor_and_retry_policy() -> None:
    from infrastructure.graph.neo4j_client_ops import ensure_query_executor, is_retryable_write

    existing = object()
    assert ensure_query_executor(
        existing,
        session_factory=lambda: None,
        settings=SimpleNamespace(retry_attempts=3, log_latency=False, retry_attempts_by_operation={}),
    ) is existing
    assert is_retryable_write(None, "neo4j.upsert_triplets") is False
    assert is_retryable_write("request-a", "neo4j.upsert_triplets") is True


def test_ollama_helpers_parse_options_payload_and_fallbacks() -> None:
    from infrastructure.providers.ollama_helpers import (
        build_chat_payload,
        chat_options_from_env,
        clean_embedding_text,
        generate_endpoint_candidates,
        infer_embedding_dim,
    )

    with patch.dict(
        "os.environ",
        {
            "OLLAMA_CHAT_TIMEOUT": "bad",
            "OLLAMA_CHAT_RETRIES": "2",
            "OLLAMA_NUM_PREDICT": "120",
            "OLLAMA_TOP_P": "0.7",
        },
        clear=False,
    ):
        options = chat_options_from_env(None)

    assert options.timeout == 120.0
    assert options.retries == 2
    assert options.num_predict == 120
    assert options.top_p == 0.7
    assert infer_embedding_dim("bge-m3") == 1024
    assert clean_embedding_text("a\x00b\n\n\nc", max_chars=20) == "ab\n\nc"
    assert generate_endpoint_candidates("http://ollama:11434/api") == [
        "http://ollama:11434/api/generate",
        "http://ollama:11434/generate",
        "http://ollama:11434/api/generate",
    ]
    assert build_chat_payload(model="m", system="s", messages=[{"role": "user", "content": "q"}], options=options)[
        "options"
    ]["num_predict"] == 120


def test_startup_checks_accept_injected_dependency_checks() -> None:
    from api.startup_checks import run_startup_checks

    calls: list[str] = []

    def check_qdrant(timeout: float) -> None:
        calls.append(f"qdrant:{timeout}")
        if calls.count(f"qdrant:{timeout}") == 1:
            raise RuntimeError("not yet")

    def check_neo4j() -> None:
        calls.append("neo4j")

    def check_provider(service: object, settings: object, timeout: float) -> None:
        calls.append(f"provider:{timeout}")

    settings = SimpleNamespace(
        startup_check_attempts=2,
        startup_check_timeout_seconds=1.0,
        startup_check_backoff_seconds=0.01,
        startup_checks_enabled=True,
        rag_default_provider="ollama",
    )

    with patch("api.startup_checks.time.sleep"):
        run_startup_checks(
            settings,
            service=object(),
            logger=logging.getLogger("startup-boundary-test"),
            check_qdrant_fn=check_qdrant,
            check_neo4j_fn=check_neo4j,
            check_provider_fn=check_provider,
        )

    assert calls == ["qdrant:1.0", "qdrant:1.0", "neo4j", "provider:1.0"]
