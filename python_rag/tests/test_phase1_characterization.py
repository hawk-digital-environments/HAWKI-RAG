from __future__ import annotations

import importlib
import json
import os
import sys
import tempfile
import types
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
from fastapi.testclient import TestClient

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))


def _install_optional_dependency_stubs() -> None:
    if "neo4j" not in sys.modules:
        neo4j_module = types.ModuleType("neo4j")

        class Neo4jError(Exception):
            pass

        class GraphDatabase:
            @staticmethod
            def driver(*args, **kwargs):
                raise RuntimeError("GraphDatabase.driver should not be called in characterization tests")

        neo4j_module.GraphDatabase = GraphDatabase
        neo4j_module.exceptions = types.SimpleNamespace(Neo4jError=Neo4jError)
        sys.modules["neo4j"] = neo4j_module


_install_optional_dependency_stubs()


class GraphFallbackCharacterizationTests(unittest.TestCase):
    def test_graph_provider_helper_clones_and_applies_explicit_graph_model(self) -> None:
        from core.graph.provider_config import clone_provider_for_graph, provider_fingerprint

        class Provider:
            def __init__(self) -> None:
                self.base = "http://ollama"
                self.key = "secret-key-material"
                self.rag_model = "default-model"
                self.embed_model = "bge-m3"
                self._explicit_graph_model = ""

        provider = Provider()
        provider._explicit_graph_model = "graph-model"

        clone = clone_provider_for_graph(provider)

        self.assertIsNot(clone, provider)
        self.assertEqual(clone.rag_model, "graph-model")
        self.assertEqual(provider.rag_model, "default-model")
        self.assertIn("secret-k", provider_fingerprint(provider))
        self.assertNotIn("secret-key-material", provider_fingerprint(provider))

    def test_raganything_edge_parser_prefers_recent_edges_for_current_file(self) -> None:
        from core.graph.edge_parser import triplets_from_raganything_edges

        edges = [
            {
                "source": "Old",
                "target": "Qdrant",
                "keywords": "old relation",
                "file_path": "/tmp/doc.md",
                "created_at": 10,
            },
            {
                "source": "HAWKI",
                "target": "Neo4j",
                "keywords": ["persists relations", "extra"],
                "file_path": "/tmp/doc.md",
                "created_at": 100,
            },
            {
                "source": "Other",
                "target": "Ignored",
                "keywords": "ignored",
                "file_path": "/tmp/other.md",
                "created_at": 100,
            },
            {
                "source": "HAWKI",
                "target": "Neo4j",
                "keywords": ["persists relations", "extra"],
                "file_path": "/tmp/doc.md",
                "created_at": 100,
            },
        ]

        self.assertEqual(
            triplets_from_raganything_edges(
                edges=edges,
                file_ref="/tmp/doc.md",
                created_at_floor=100,
            ),
            [("HAWKI", "persists relations", "Neo4j")],
        )

    def test_graph_cache_clear_removes_doc_status_and_lightrag_cache_files(self) -> None:
        from core.graph.cache import clear_graph_cache_files

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            removable = [
                root / "kv_store_doc_status_chunk_1.json",
                root / "kv_store_full_docs.json",
                root / "kv_store_text_chunks.json",
                root / "kv_store_llm_response_cache.json",
                root / "vdb_entities.json",
            ]
            keep = root / "notes.txt"
            for path in [*removable, keep]:
                path.write_text("x", encoding="utf-8")

            result = clear_graph_cache_files(root)

            self.assertTrue(result["ok"])
            self.assertEqual(result["failed"], {})
            self.assertTrue(keep.exists())
            self.assertTrue(all(not path.exists() for path in removable))

    def test_raganything_llm_cache_fallback_recovers_delimited_and_table_relations(self) -> None:
        from core.rag_service import RAGService

        with tempfile.TemporaryDirectory() as tmp:
            cache_path = Path(tmp) / "kv_store_llm_response_cache.json"
            cache_path.write_text(
                json.dumps(
                    {
                        "a": {
                            "return": "\n".join(
                                [
                                    "relation<|#|>HAWKI<|#|>Retrieval-Augmented Generation<|#|>is defined by",
                                    "relationship|source|target",
                                    "---|---|---",
                                    "uses vector search|HAWKI|Qdrant",
                                    "persists relations|HAWKI|Neo4j",
                                ]
                            )
                        }
                    }
                ),
                encoding="utf-8",
            )

            service = object.__new__(RAGService)
            service.working_dir = Path(tmp)

            self.assertEqual(
                service._triplets_from_raganything_llm_cache(),
                [
                    ("HAWKI", "is defined by", "Retrieval-Augmented Generation"),
                    ("HAWKI", "uses vector search", "Qdrant"),
                    ("HAWKI", "persists relations", "Neo4j"),
                ],
            )

    def test_source_filter_drops_prompt_examples_and_keeps_grounded_triplets(self) -> None:
        from graph.graph_utils import filter_triplets_to_source

        source = "HAWKI uses Qdrant for vector search and Neo4j for graph relationships."
        triplets = [
            ("HAWKI", "uses", "Qdrant"),
            ("Evolutionary Search", "compares with", "NASBench-360"),
            ("Unseen System", "mentions", "Unseen Object"),
        ]

        self.assertEqual(
            filter_triplets_to_source(triplets, source),
            [("HAWKI", "uses", "Qdrant")],
        )

    def test_graph_text_cleaner_normalizes_with_env_limits(self) -> None:
        from core.graph.text import clean_graph_text

        with patch.dict(
            os.environ,
            {
                "GRAPH_MAX_LINES": "3",
                "GRAPH_MAX_CHARS": "2000",
                "MAX_EXTRACT_INPUT_TOKENS": "2",
            },
            clear=False,
        ):
            self.assertEqual(
                clean_graph_text("  a  \n\n  b  \n ccc ddd eee fff ggg "),
                "a b",
            )

    def test_graph_from_text_uses_graph_perf_log_injection(self) -> None:
        from graph.graph_text import graph_from_text

        class FakeGraph:
            def __init__(self):
                self.triplets: list[tuple[str, str, str]] = []
                self.closed = False

            def upsert_triplets(self, triplets: list[tuple[str, str, str]]) -> None:
                self.triplets = triplets

            def close(self) -> None:
                self.closed = True

        class FakeService:
            def extract_triplets(self, text: str, engine: str) -> list[tuple[str, str, str]]:
                return [("A", "R", "B"), ("B", "S", "C")]

        fake_graph = FakeGraph()
        with patch("graph.graph_text.Neo4jGraph", return_value=fake_graph):
            result = graph_from_text(
                SimpleNamespace(text="sample", engine="engine-a"),
                rag_service=FakeService(),
                graph_perf_log=True,
            )

        self.assertEqual(result["ok"], True)
        self.assertEqual(result["triplets"], 2)
        self.assertEqual(fake_graph.triplets, [("A", "R", "B"), ("B", "S", "C")])
        self.assertTrue(fake_graph.closed)

    def test_graph_visualization_write_can_be_disabled_with_injected_settings(self) -> None:
        from graph.graph_visualization import write_graph_visualization
        from graph.visualization_settings import GraphVisualizationSettings

        settings = GraphVisualizationSettings(
            enabled=False,
            uri="bolt://neo4j:7687",
            user="neo4j",
            password="password",
            database=None,
            limit=10,
        )

        with tempfile.TemporaryDirectory() as tmp, patch("graph.graph_visualization.Neo4jGraphVisualization") as mocked_vis:
            result = write_graph_visualization(Path(tmp), settings=settings)
            self.assertIsNone(result)
            mocked_vis.assert_not_called()

    def test_graph_visualization_writer_uses_injected_settings(self) -> None:
        from graph.graph_visualization import write_graph_visualization
        from graph.visualization_settings import GraphVisualizationSettings
        from unittest.mock import MagicMock

        snapshot_payload = {
            "ok": True,
            "generated_at": "2026-06-10T00:00:00+00:00",
            "limit": 3,
            "node_count": 1,
            "relationship_count": 0,
            "recent_doc_id": "toy-1",
            "recent_relationship_count": 0,
            "document_count": 0,
            "nodes": [{"id": "a", "label": "A", "labels": []}],
            "links": [],
        }
        fake_visualizer = SimpleNamespace(
            snapshot=MagicMock(return_value=snapshot_payload),
            close=MagicMock(),
        )
        settings = GraphVisualizationSettings(
            enabled=True,
            uri="bolt://neo4j:7687",
            user="neo4j",
            password="password",
            database="neo4j_graph_db",
            limit=7,
        )

        with tempfile.TemporaryDirectory() as tmp, patch(
            "graph.graph_visualization.Neo4jGraphVisualization",
            return_value=fake_visualizer,
        ) as mocked_vis:
            out = write_graph_visualization(
                Path(tmp),
                database="db-from-arg",
                settings=settings,
                limit=3,
                recent_doc_id="toy-1",
            )

            self.assertIsNotNone(out)
            out_path = out
            assert out_path is not None
            self.assertTrue(out_path.exists())
            self.assertEqual(fake_visualizer.snapshot.call_args.kwargs["limit"], 3)
            self.assertIn("db-from-arg", mocked_vis.call_args.kwargs["database"])

            written = json.loads((Path(tmp) / "neo4j_graph_visualization.json").read_text(encoding="utf-8"))
            self.assertEqual(written["ok"], True)
            self.assertEqual(written["nodes"], snapshot_payload["nodes"])


class RagAnythingGraphSettingsCharacterizationTests(unittest.TestCase):
    def test_raganything_graph_settings_parse_and_injected_runtime_summary(self) -> None:
        from core.graph.raganything_client import RagAnythingGraphService
        from core.graph.raganything_settings import load_raganything_graph_settings

        with patch.dict(
            os.environ,
            {
                "NEO4J_URI": "",
                "NEO4J_BOLT_URL": "bolt://127.0.0.1:7687",
                "NEO4J_HTTP_URL": "https://127.0.0.1:7474",
                "NEO4J_USER": "graph-user",
                "NEO4J_PASSWORD": "graph-pass",
                "NEO4J_DATABASE": "rag-db",
                "GRAPH_TEMPERATURE": "0.2",
                "OLLAMA_CHAT_TIMEOUT": "77",
                "GRAPH_RESET_CACHE_PER_DOC": "no",
                "GRAPH_EMBED_JUNK_STRICT": "false",
                "GRAPH_EMBED_JUNK_DENYLIST": "nonsense",
                "GRAPH_EMBED_JUNK_ALLOWLIST": "kept",
                "OLLAMA_EMBED_NAN_ZERO_FALLBACK": "true",
                "GRAPH_DOC_MAX_CHARS": "2500",
                "GRAPH_DOC_MAX_CHUNKS": "2",
                "GRAPH_MIN_CHUNK_CHARS": "40",
                "GRAPH_MIN_DOC_CHARS": "120",
                "GRAPH_OLLAMA_RAG_MODEL": "graph-model-v1",
                "OLLAMA_EMBED_MODEL": "embed-model-v1",
            },
            clear=False,
        ):
            settings = load_raganything_graph_settings()

        self.assertEqual(settings.neo4j_uri, "bolt://127.0.0.1:7687")
        self.assertEqual(settings.neo4j_user, "graph-user")
        self.assertFalse(settings.graph_reset_cache_per_doc)
        self.assertFalse(settings.graph_embed_junk_strict)
        self.assertEqual(settings.graph_embed_junk_denylist, "nonsense")
        self.assertEqual(settings.graph_doc_max_chars, 2500)

        with tempfile.TemporaryDirectory() as tmp:
            service = RagAnythingGraphService(
                Path(tmp),
                settings=settings,
                logger_obj=None,
            )
            summary = service.graph_runtime_summary()

        self.assertEqual(summary["neo4j"]["uri"], "bolt://127.0.0.1:7687")
        self.assertEqual(summary["neo4j"]["database"], "rag-db")
        self.assertEqual(summary["models"]["graph_model"], "graph-model-v1")
        self.assertEqual(summary["models"]["embed_model"], "embed-model-v1")
        self.assertEqual(summary["limits"]["graph_doc_max_chars"], 2500)
        self.assertFalse(summary["resilience"]["graph_embed_junk_strict"])


class RagAnythingUtilsCharacterizationTests(unittest.TestCase):
    def test_graph_utils_normalization_and_dedupe(self) -> None:
        from core.graph.raganything_utils import dedupe_triplets, normalize_graph_embed_text

        self.assertEqual(normalize_graph_embed_text("  hello\n\tworld  "), "hello world")

        triplets = [
            (" a ", "USES", " B "),
            ("a", "USES", "B"),
            ("a", "IS", "C"),
            ("", "EMPTY", "D"),
            ("a", "USES", "  "),
            ("A", "USES", "B"),
        ]

        self.assertEqual(
            dedupe_triplets(triplets),
            [("a", "USES", "B"), ("a", "IS", "C"), ("A", "USES", "B")],
        )

    def test_graph_utils_junk_reasoning(self) -> None:
        from core.graph.raganything_utils import graph_embed_junk_reason, is_junk_graph_label

        self.assertEqual(graph_embed_junk_reason(""), "empty")
        self.assertTrue(is_junk_graph_label("N/A", strict=False))
        self.assertFalse(
            is_junk_graph_label(
                "GraphNode",
                allowlist_raw="exact:GraphNode",
                strict=False,
            )
        )
        self.assertTrue(
            is_junk_graph_label(
                "noise item",
                denylist_raw="contains:noise",
                strict=False,
            )
        )
        self.assertEqual(graph_embed_junk_reason("Skip to main content"), "strict_boilerplate_label")


class RagAnythingClientModuleCharacterizationTests(unittest.TestCase):
    def test_graph_cache_key_changes_with_db_name(self) -> None:
        from core.graph.raganything_client_config import graph_runtime_cache_key
        from core.graph.raganything_settings import load_raganything_graph_settings

        class Provider:
            def __init__(self) -> None:
                self.base = "http://ollama"
                self.rag_model = "rag"
                self.embed_model = "emb"
                self._explicit_graph_model = ""

        with tempfile.TemporaryDirectory() as tmp:
            settings = load_raganything_graph_settings()
            provider = Provider()

            key_default = graph_runtime_cache_key(
                Path(tmp),
                provider,
                settings,
                neo4j_database="db-a",
            )
            key_alt = graph_runtime_cache_key(
                Path(tmp),
                provider,
                settings,
                neo4j_database="db-b",
            )

            self.assertNotEqual(key_default, key_alt)
            self.assertIn("db-a", key_default)
            self.assertIn("db-b", key_alt)

    def test_graph_extract_helpers_produce_stable_ids(self) -> None:
        from core.graph.raganything_extract import (
            graph_content_list_from_input,
            raganything_file_ref,
            stable_raganything_doc_id,
        )

        content = graph_content_list_from_input(
            "line1\nline2",
            [" chunk ", "", "  keep  "],
        )
        self.assertEqual(
            content,
            [
                {"type": "text", "text": "chunk", "page_idx": 0},
                {"type": "text", "text": "keep", "page_idx": 2},
            ],
        )

        doc_id = stable_raganything_doc_id("doc", "source.txt", content)
        self.assertTrue(doc_id.startswith("doc:"))

        file_ref = raganything_file_ref("doc", "/tmp/source.txt")
        self.assertIn("doc__source.txt", file_ref)

    def test_graph_cache_scrub_can_cleanup_full_or_doc_scope(self) -> None:
        from core.graph.raganything_cache import scrub_raganything_kv_graph_junk
        from core.graph.raganything_utils import is_junk_graph_label

        with tempfile.TemporaryDirectory() as tmp:
            working_dir = Path(tmp)
            (working_dir / "kv_store_full_entities.json").write_text(
                json.dumps(
                    {
                        "doc-1": {"entity_names": ["HAWKI", "keep me", "N/A"]},
                        "doc-2": {"entity_names": ["keep", "noise"]},
                    }
                ),
                encoding="utf-8",
            )
            (working_dir / "kv_store_full_relations.json").write_text(
                json.dumps(
                    {
                        "doc-1": {"relation_pairs": [["HAWKI", "connects", "RAG"], ["N/A", "links", "Tool"], ["ok", "is", "good"]]},
                    }
                ),
                encoding="utf-8",
            )
            (working_dir / "kv_store_entity_chunks.json").write_text(
                json.dumps({"HAWKI": 1, "N/A": 1, "keep": 1}, ensure_ascii=False),
                encoding="utf-8",
            )
            (working_dir / "kv_store_relation_chunks.json").write_text(
                json.dumps({"HAWKI<SEP>RAG": 1, "N/A<SEP>Tool": 1}, ensure_ascii=False),
                encoding="utf-8",
            )

            result = scrub_raganything_kv_graph_junk(
                working_dir=working_dir,
                is_junk_graph_label=lambda value: is_junk_graph_label(value, strict=False),
                rag_doc_id="doc-1",
                full_scan=False,
            )

            self.assertEqual(result["full_entities_docs"], 1)
            self.assertGreaterEqual(result["full_entities_names"], 1)
            self.assertEqual(result["full_relations_pairs"], 1)
            self.assertEqual(result["entity_chunks"], 1)
            self.assertEqual(result["relation_chunks"], 1)


class RagAnythingSummaryCharacterizationTests(unittest.TestCase):
    def test_graph_runtime_summary_builder_returns_expected_shape(self) -> None:
        from core.graph.raganything_summary import build_graph_runtime_summary
        from core.graph.raganything_settings import load_raganything_graph_settings

        with tempfile.TemporaryDirectory() as tmp:
            working_dir = Path(tmp)
            for i in range(2):
                (working_dir / f"kv_store_doc_status_chunk_{i}.json").write_text("{}", encoding="utf-8")

            with patch.dict(
                os.environ,
                {
                    "NEO4J_URI": "bolt://from-env:7687",
                    "NEO4J_USER": "neo-user",
                    "NEO4J_DATABASE": "env-db",
                },
                clear=False,
            ):
                settings = load_raganything_graph_settings()

            summary = build_graph_runtime_summary(
                working_dir=working_dir,
                settings=settings,
                runtime_meta={"doc_status_storage": "ChunkedJsonDocStatusStorage", "graph_storage": "Neo4JStorage"},
                graph_client_initialized=True,
            )

        self.assertEqual(summary["doc_status_chunks"]["count"], 2)
        self.assertEqual(summary["doc_status_storage"], "ChunkedJsonDocStatusStorage")
        self.assertEqual(summary["graph_storage"], "Neo4JStorage")
        self.assertEqual(summary["graph_client_initialized"], True)


class RagAnythingLoopCharacterizationTests(unittest.TestCase):
    def test_graph_loop_runs_sync_coro(self) -> None:
        from core.graph.raganything_loop import RagAnythingGraphLoop

        async def _value() -> str:
            return "ok"

        loop = RagAnythingGraphLoop()
        self.assertEqual(loop.run_sync(_value()), "ok")


class RagAnythingRuntimeCharacterizationTests(unittest.TestCase):
    def test_prepare_lightrag_neo4j_env_sets_runtime_variables(self) -> None:
        from core.graph.raganything_runtime import prepare_lightrag_neo4j_env
        from core.graph.raganything_settings import load_raganything_graph_settings

        with patch.dict(
            os.environ,
            {
                "NEO4J_URI": "bolt://existing-db:7687",
                "NEO4J_BOLT_URL": "bolt://lightrag:7687",
                "NEO4J_HTTP_URL": "",
                "NEO4J_USER": "neo4j-user",
                "NEO4J_PASSWORD": "neo4j-pass",
                "NEO4J_DATABASE": "base-db",
            },
            clear=False,
        ):
            settings = load_raganything_graph_settings()

            ready, applied = prepare_lightrag_neo4j_env(settings)

        self.assertTrue(ready)
        self.assertIn("NEO4J_USERNAME", applied)
        self.assertEqual(applied["NEO4J_DATABASE"], "base-db")
        self.assertEqual(applied["NEO4J_USERNAME"], "neo4j-user")
        self.assertNotIn("NEO4J_URI", applied)


class IngestCharacterizationTests(unittest.TestCase):
    def test_validate_ingest_document_reports_invalid_shape_and_metadata_warnings(self) -> None:
        from pipeline.validation import normalize_ingest_metadata, validate_ingest_document

        missing = SimpleNamespace(id=" ", text="", payload={"converted_path": "/tmp/sample-toys.md"})
        errors, warnings = validate_ingest_document(missing)

        self.assertIn("doc id is missing.", errors)
        self.assertIn("document text is empty.", errors)
        self.assertIn("metadata URL is missing.", warnings)
        self.assertIn("metadata title is missing.", warnings)

        bad_payload = SimpleNamespace(id="doc-1", text="Toy train", payload="not-a-dict")
        errors, warnings = validate_ingest_document(bad_payload)

        self.assertEqual(errors, ["document payload must be an object."])
        self.assertEqual(warnings, [])

        normalized = normalize_ingest_metadata(
            SimpleNamespace(
                id="doc-2",
                text="Toy blocks",
                payload={"original_filename": "toy_catalog.docx", "url": "upload://toy_catalog.docx"},
            )
        )

        self.assertEqual(normalized["title"], "toy_catalog")
        self.assertEqual(normalized["source_url"], "upload://toy_catalog.docx")
        self.assertEqual(normalized["page_url"], "upload://toy_catalog.docx")

    def test_prepare_documents_skips_invalid_docs_and_tracks_chunks(self) -> None:
        from pipeline.ingest.document_prep import prepare_documents

        docs = [
            SimpleNamespace(
                id="doc-1",
                text="Toy train. Toy blocks.",
                payload={"title": "Toys", "source_url": "upload://toys.md", "source_format": "markdown"},
            ),
            SimpleNamespace(id="", text="Skipped", payload={"title": "Invalid"}),
        ]

        chunk_records, stats = prepare_documents(
            docs,
            chunk_chars=100,
            chunk_overlap=0,
            default_job_id="job-1",
        )

        self.assertEqual(len(chunk_records), 1)
        self.assertEqual(stats["processed_docs"], 1)
        self.assertEqual(stats["skipped_docs"], 1)
        self.assertEqual(stats["chunks_per_doc"], {"doc-1": 1})
        self.assertEqual(stats["validation_failures"][0]["doc_id"], "")
        self.assertEqual(chunk_records[0]["payload"]["doc_id"], "doc-1")
        self.assertEqual(chunk_records[0]["payload"]["component_type"], "chunk")

    def test_ingest_request_helpers_infer_job_and_apply_provider_overrides(self) -> None:
        from pipeline.ingest.request import apply_provider_overrides, infer_job_id

        docs = [SimpleNamespace(id="doc-1", payload={"trace_id": "trace-1"})]
        body = SimpleNamespace(job_id=None, embedding_model="embed-v2", graph_model="graph-v2")

        class Provider:
            embed_model = "embed-v1"
            rag_model = "graph-v1"

        provider = Provider()
        apply_provider_overrides(provider, body)

        self.assertEqual(infer_job_id(body, docs), "trace-1")
        self.assertEqual(provider.embed_model, "embed-v2")
        self.assertEqual(provider.rag_model, "graph-v2")
        self.assertEqual(provider._explicit_graph_model, "graph-v2")

    def test_ingest_documents_dry_run_returns_request_summary_shape(self) -> None:
        from pipeline.ingest_logic import ingest_documents

        body = SimpleNamespace(
            docs=[
                SimpleNamespace(
                    id="doc-1",
                    text="HAWKI uses Qdrant and Neo4j.",
                    payload={
                        "title": "HAWKI",
                        "source_url": "upload://hawki.md",
                        "source_format": "markdown",
                    },
                )
            ],
            dry_run=True,
            dry_include_graph=False,
            provider="fake",
            collection="hawki_test",
            graph=False,
            graph_engine="raganything",
            graph_only=False,
            chunk_chars=1200,
            chunk_overlap=250,
            batch_size=64,
            distance="Cosine",
            neo4j_database=None,
        )

        result = ingest_documents(
            body,
            rag_service=object(),
            get_provider=lambda name: object(),
            public_dir=Path(tempfile.gettempdir()),
        )

        self.assertTrue(result["ok"])
        self.assertTrue(result["dry_run"])
        summary = result["summary"]
        self.assertTrue(summary["estimate_only"])
        self.assertEqual(summary["planned_points"], 1)
        self.assertEqual(summary["qdrant_preview"]["collection"], "hawki_test")
        self.assertEqual(summary["documents"]["doc_ids"], ["doc-1"])
        self.assertEqual(summary["documents"]["by_format"], {"markdown": 1})

    def test_build_points_creates_deterministic_qdrant_point_payload(self) -> None:
        from pipeline.ingest.vector_ingest import build_points

        class Provider:
            def embed(self, text: str) -> list[float]:
                self.last_text = text
                return [0.1, 0.2, 0.3]

        chunk_records = [
            {
                "doc_id": "doc-1",
                "content": "HAWKI content",
                "payload": {
                    "doc_id": "doc-1",
                    "chunk_index": 0,
                    "title": "HAWKI",
                    "source_url": "upload://hawki.md",
                    "content": "HAWKI content",
                },
            }
        ]

        points, vector_size, failures = build_points(chunk_records, Provider())

        self.assertEqual(vector_size, 3)
        self.assertEqual(failures, [])
        self.assertEqual(len(points), 1)
        self.assertEqual(points[0]["vector"], [0.1, 0.2, 0.3])
        self.assertEqual(points[0]["payload"]["doc_id"], "doc-1")
        self.assertEqual(points[0]["payload"]["chunk_index"], 0)
        self.assertRegex(points[0]["id"], r"^[0-9a-f-]{36}$")

    def test_graph_ingest_settings_load_from_env(self) -> None:
        from pipeline.ingest.settings import load_graph_ingest_settings
        from pipeline.ingest.graph_ingest import graph_failure_log_path

        with patch.dict(
            os.environ,
            {
                "GRAPH_DEBUG": "true",
                "GRAPH_PERF_LOG": "1",
                "GRAPH_DOC_TIMEOUT": "12",
                "GRAPH_DOC_MAX_CHARS": "777",
                "GRAPH_DOC_MAX_CHUNKS": "5",
                "GRAPH_FAILURE_LOG": "/tmp/custom_graph_failures.log",
            },
            clear=False,
        ):
            settings = load_graph_ingest_settings()
            self.assertEqual(str(graph_failure_log_path(Path(tempfile.gettempdir()))), "/tmp/custom_graph_failures.log")

        self.assertTrue(settings.graph_debug)
        self.assertTrue(settings.graph_perf_log)
        self.assertEqual(settings.graph_doc_timeout_s, 12.0)
        self.assertEqual(settings.graph_doc_max_chars, 777)
        self.assertEqual(settings.graph_doc_max_chunks, 5)


class IngestRunnerCharacterizationTests(unittest.TestCase):
    def test_ingest_crawled_main_delegates_to_runner(self) -> None:
        from ingest import ingest_crawled

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "crawl"
            root.mkdir()
            with patch("ingest.ingest_crawled.run_ingest", return_value=123) as run_mock:
                exit_code = ingest_crawled.main(["--root", str(root)])

            run_mock.assert_called_once()
            self.assertEqual(exit_code, 123)

    def test_runner_no_pages_returns_partial_and_writes_summary(self) -> None:
        from ingest import ingest_crawled
        from ingest.runner import run_ingest, EXIT_PARTIAL_SUCCESS

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "crawl"
            root.mkdir()
            summary_payload: dict[str, object] = {}

            def fake_write_summary(path: str | None, payload: dict[str, object]) -> None:
                summary_payload["path"] = path
                summary_payload["payload"] = payload

            args = ingest_crawled.parse_args(["--root", str(root), "--summary-file", str(Path(tmp) / "summary.json")])

            with patch("ingest.runner.discover_page_dirs", return_value=[]), patch(
                "ingest.submit.write_summary_file",
                side_effect=fake_write_summary,
            ), patch("ingest.runner.build_url_maps", return_value=({}, {})):
                exit_code = run_ingest(args)

            self.assertEqual(exit_code, EXIT_PARTIAL_SUCCESS)
            payload = summary_payload.get("payload")
            self.assertIsInstance(payload, dict)
            self.assertEqual(payload.get("reason"), "no_pages_found")
            self.assertIsNotNone(summary_payload.get("path"))

    def test_runner_estimate_only_returns_success(self) -> None:
        from ingest import ingest_crawled
        from ingest.runner import run_ingest

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / "crawl"
            page = root / "page"
            page.mkdir(parents=True)
            (page / "content.md").write_text("This is a toy catalog.", encoding="utf-8")

            args = ingest_crawled.parse_args(["--root", str(root), "--estimate-only"])

            with patch("ingest.runner.discover_page_dirs", return_value=[page]), patch(
                "ingest.runner.run_local_estimate"
            ) as estimate_mock:
                estimate_mock.return_value = {
                    "timestamp": "2026-06-10T00:00:00Z",
                    "estimate_only": True,
                    "documents": {"total_docs": 1},
                    "qdrant_preview": {"planned_batches": 1},
                }
                exit_code = run_ingest(args)

            estimate_mock.assert_called_once()
            self.assertEqual(exit_code, 0)


class Neo4jCharacterizationTests(unittest.TestCase):
    def test_neo4j_response_parsing_is_robust(self) -> None:
        from graph.neo4j_responses import (
            parse_count,
            parse_fact_rows,
            parse_label_counts,
            parse_relation_counts,
            parse_structural_rows,
        )

        class Row:
            def __init__(self, values: dict[str, object]):
                self._values = values

            def get(self, key: str, default: object | None = None) -> object:
                return self._values.get(key, default)

        self.assertEqual(parse_count(None), 0)
        self.assertEqual(
            parse_count(Row({"c": "7"})),
            7,
        )
        self.assertEqual(
            parse_relation_counts([Row({"rel_type": "USES", "count": "2"}), Row({"rel_type": "WROTE", "count": 1})]),
            [{"type": "USES", "count": 2}, {"type": "WROTE", "count": 1}],
        )
        self.assertEqual(
            parse_label_counts([Row({"labels": ("A", "B"), "count": 1}), Row({"labels": [], "count": 0})]),
            [{"labels": ["A", "B"], "count": 1}, {"labels": [], "count": 0}],
        )
        self.assertEqual(
            parse_fact_rows(
                [
                    Row({"subject": "S", "relation": "R", "object": "O"}),
                    Row({"subject": "bad", "relation": "R"}),
                ]
            ),
            [{"subject": "S", "relation": "R", "object": "O"}],
        )
        self.assertEqual(
            parse_structural_rows(
                [
                    Row({"subject": "S", "relation": "R", "object": "O", "doc_id": "d", "hops": "3"}),
                    Row({"subject": "S2", "relation": "R2", "object": "O2", "hops": None}),
                ]
            ),
            [
                {"subject": "S", "relation": "R", "object": "O", "doc_id": "d", "hops": 3},
                {"subject": "S2", "relation": "R2", "object": "O2", "doc_id": None, "hops": 1},
            ],
        )

    def test_upsert_triplets_builds_doc_scoped_rows_for_neo4j(self) -> None:
        from graph.neo4j_graph import Neo4jGraph

        calls: list[tuple[str, dict]] = []

        class Tx:
            def run(self, cypher: str, **params):
                calls.append((cypher, params))

        class Session:
            def __enter__(self):
                return self

            def __exit__(self, exc_type, exc, tb):
                return False

            def execute_write(self, callback):
                return callback(Tx())

        graph = object.__new__(Neo4jGraph)
        graph._database = None
        graph._session = lambda: Session()

        graph.upsert_triplets(
            [("HAWKI", "USES", "Qdrant"), ("HAWKI", "PERSISTS", "Neo4j")],
            doc_id="doc-1",
        )

        self.assertEqual(len(calls), 1)
        cypher, params = calls[0]
        self.assertIn("MERGE (s:Entity {name: row.s})", cypher)
        self.assertEqual(
            params["rows"],
            [
                {"s": "HAWKI", "r": "USES", "o": "Qdrant", "doc_id": "doc-1"},
                {"s": "HAWKI", "r": "PERSISTS", "o": "Neo4j", "doc_id": "doc-1"},
            ],
        )

    def test_neo4j_query_executor_retries_transient_errors(self) -> None:
        from graph.neo4j_requests import Neo4jQueryRequest
        from graph.neo4j_transport import Neo4jQueryExecutor
        from neo4j import exceptions as neo4j_exceptions

        attempts: list[int] = []

        class Session:
            def __init__(self) -> None:
                attempts.append(1)

            def __enter__(self):
                return self

            def __exit__(self, exc_type, exc, tb):
                return False

            def execute_read(self, callback):
                if len(attempts) < 2:
                    raise neo4j_exceptions.Neo4jError("retry now")
                return callback(self)

        executed: list[bool] = []
        executor = Neo4jQueryExecutor(
            session_factory=Session,
            retry_attempts=3,
            log_latency=False,
            backoff_seconds=0.0,
        )
        result = executor.run_read(
            Neo4jQueryRequest("RETURN 1", {}),
            callback=lambda tx: executed.append(True) or "ok",
        )

        self.assertEqual(result, "ok")
        self.assertEqual(len(attempts), 2)
        self.assertEqual(executed, [True])

    def test_neo4j_graph_accepts_injected_query_executor(self) -> None:
        from graph.neo4j_graph import Neo4jGraph
        from types import SimpleNamespace

        class FakeExecutor:
            def __init__(self) -> None:
                self.read_calls = 0
                self.write_calls = 0
                self.statements: list[str] = []

            def run_read(self, request, callback):
                self.read_calls += 1
                self.statements.append(request.statement)

                class Tx:
                    def run(self, _statement: str, **_params: str) -> list[dict[str, str]]:
                        return [{"subject": "A", "relation": "R", "object": "B"}]

                return callback(Tx())

            def run_write(self, request, callback):
                self.write_calls += 1
                self.statements.append(request.statement)

                class Tx:
                    def run(self, statement: str, **_params: str):
                        return {"statement": statement}

                return callback(Tx())

        executor = FakeExecutor()
        graph = Neo4jGraph(
            settings=SimpleNamespace(database=None, retry_attempts=1, log_latency=False, perf_log=False),
            query_executor=executor,  # type: ignore[arg-type]
        )
        fetch_result = graph.fetch_related(["toy"])
        graph.upsert_triplets([("A", "R", "B")], doc_id="doc-1")

        self.assertEqual(fetch_result, [{"subject": "A", "relation": "R", "object": "B"}])
        self.assertEqual(executor.read_calls, 1)
        self.assertEqual(executor.write_calls, 1)
        self.assertTrue(any("UNWIND $rows" in statement for statement in executor.statements))


class QueryCharacterizationTests(unittest.TestCase):
    def test_query_lexical_helpers_fold_fuzzy_match_and_boost_scores(self) -> None:
        from pipeline.query_lexical import (
            extract_query_terms_for_lexical,
            fold_text,
            fuzzy_term_in_words,
            lexical_boost_hits,
        )

        hits = [
            {
                "id": "a",
                "score": 0.1,
                "payload": {
                    "title": "Holzspielzeug",
                    "content": "Robuste Baukloetze und Holzspielzeug fuer Kinder",
                    "doc_id": "doc-a",
                },
            },
            {
                "id": "b",
                "score": 0.9,
                "payload": {"title": "Other", "content": "Unrelated text", "doc_id": "doc-b"},
            },
        ]

        boosted = lexical_boost_hits(hits, "Bauklötze Holzspielzeug")

        self.assertEqual(fold_text("Bauklötze für große Kinder"), "bauklotze fur grosse kinder")
        self.assertTrue(fuzzy_term_in_words("blocks", ["block"]))
        self.assertIn("bauklotze", extract_query_terms_for_lexical("Bauklötze"))
        self.assertEqual([hit["payload"]["doc_id"] for hit in boosted], ["doc-a"])
        self.assertGreater(boosted[0]["score"], 0.1)

    def test_query_settings_parse_env_with_caps_and_fallbacks(self) -> None:
        from pipeline.query_settings import (
            context_limits,
            fusion_weights,
            generation_enabled,
            iterative_retrieval_enabled,
            score_thresholds,
            search_top_k,
        )

        with patch.dict(
            os.environ,
            {
                "RAG_SEARCH_TOP_K_MULT": "4",
                "RAG_SEARCH_TOP_K_CAP": "15",
                "RAG_FUSION_SEM_WEIGHT": "bad",
                "RAG_FUSION_STR_WEIGHT": "0.25",
                "RAG_MIN_SCORE": "0.3",
                "RAG_MIN_SCORE_FALLBACK": "bad",
                "RAG_CONTEXT_TOKENS": "120",
                "RAG_CONTEXT_DOCS": "bad",
                "RAG_ITERATIVE_RETRIEVAL": "no",
                "RAG_GENERATE_ANSWER": "yes",
            },
            clear=False,
        ):
            self.assertEqual(search_top_k(5), 15)
            self.assertEqual(fusion_weights(), (0.6, 0.25))
            self.assertEqual(score_thresholds(), (0.3, 0.2))
            self.assertEqual(context_limits(), (120, 6))
            self.assertFalse(iterative_retrieval_enabled())
            self.assertTrue(generation_enabled())

    def test_query_fallback_uses_text_search_then_relaxed_scroll(self) -> None:
        from pipeline.query_fallback import keyword_fallback_search

        calls: list[tuple[str, bool | None]] = []

        class Qdrant:
            def search_with_text(self, vector, *, top_k, terms, fields):
                calls.append(("search", None))
                return [{"id": "a", "score": 0.5, "payload": {"doc_id": "doc-a"}}]

            def scroll_with_text(self, *, terms, fields, limit, require_all):
                calls.append(("scroll", require_all))
                if require_all:
                    return []
                return [{"id": "b", "score": 0.8, "payload": {"doc_id": "doc-b"}}]

        with patch.dict(os.environ, {"RAG_EXHAUSTIVE_TEXT": "false", "QDRANT_TEXT_SCROLL_LIMIT": "10"}, clear=False):
            hits = keyword_fallback_search(Qdrant(), [0.1], "wooden toys", 3)

        self.assertEqual([hit["payload"]["doc_id"] for hit in hits], ["doc-b", "doc-a"])
        self.assertEqual(calls, [("search", None), ("scroll", True), ("scroll", False)])

    def test_query_fallback_uses_injected_scroll_controls(self) -> None:
        from pipeline.query_fallback import keyword_fallback_search

        calls: list[tuple[str, int | bool | None]] = []

        class Qdrant:
            def search_with_text(self, vector, *, top_k, terms, fields):
                calls.append(("search", top_k))
                return []

            def scroll_with_text_all(self, *, terms, fields, limit, require_all):
                calls.append(("scroll_all", limit))
                return [{"id": "a", "score": 0.4, "payload": {"doc_id": "doc-a"}}]

            def scroll_with_text(self, *, terms, fields, limit, require_all):
                calls.append(("scroll", limit))
                return []

        hits = keyword_fallback_search(
            Qdrant(),
            [0.1],
            "wooden toys",
            3,
            text_scroll_limit_fn=lambda top_k: 7,
            exhaustive_text_fn=lambda: True,
        )

        self.assertEqual([hit["payload"]["doc_id"] for hit in hits], ["doc-a"])
        self.assertEqual(calls, [("search", 3), ("scroll_all", 7)])

    def test_query_hit_helpers_merge_dedupe_and_limit_by_doc_identity(self) -> None:
        from pipeline import query_logic

        primary = [
            {"id": "a", "score": 0.2, "payload": {"doc_id": "doc-a", "title": "Toy Train"}},
            {"id": "b", "score": 0.4, "payload": {"doc_id": "doc-b", "title": "Blocks"}},
        ]
        secondary = [
            {"id": "a2", "score": 0.9, "payload": {"doc_id": "doc-a", "title": "Toy Train Duplicate"}},
            {"id": "c", "score": 0.8, "payload": {"doc_id": "doc-c", "title": "Blocks"}},
        ]

        merged = query_logic._merge_hits(primary, secondary, limit=3)
        deduped = query_logic._dedupe_hits_by_title_or_url(merged)

        self.assertEqual([hit["payload"]["doc_id"] for hit in merged], ["doc-c", "doc-b", "doc-a"])
        self.assertEqual([hit["payload"]["doc_id"] for hit in deduped], ["doc-c", "doc-a"])

    def test_query_context_summaries_trim_to_token_budget(self) -> None:
        from pipeline import query_logic

        hits = [
            {
                "id": "a",
                "score": 0.9,
                "payload": {
                    "title": "Toy Catalog",
                    "page_url": "upload://toys.md",
                    "content": " ".join(["wooden blocks"] * 80),
                    "component_type": "chunk",
                },
            }
        ]

        summaries, trimmed, used_tokens = query_logic._prepare_context_summaries(
            hits,
            max_docs=1,
            max_tokens=20,
        )

        self.assertEqual(len(summaries), 1)
        self.assertEqual(trimmed, [1])
        self.assertLessEqual(used_tokens, 30)
        self.assertEqual(summaries[0]["title"], "Toy Catalog")

    def test_query_uses_reranked_order_without_external_services(self) -> None:
        from pipeline import query_logic

        class Provider:
            def embed(self, text: str) -> list[float]:
                return [0.5, 0.25]

        class RagService:
            def rerank_hits(self, *, hits, **kwargs):
                return sorted(hits, key=lambda hit: hit["payload"]["title"])

        body = SimpleNamespace(
            query="HAWKI architecture",
            top_k=2,
            provider="fake",
            filters={},
            generate=False,
            is_optimized=False,
            fast_mode=True,
            smart_lookup=False,
            structural_hops=0,
            preferred_tags=None,
            reranker="none",
            rerank_top_n=10,
            mix_mode=False,
            mix_weight=0.5,
        )
        hits = [
            {
                "id": "b",
                "score": 0.6,
                "payload": {
                    "title": "Vector",
                    "content": "Qdrant vector search",
                    "component_type": "chunk",
                    "doc_id": "doc-b",
                },
            },
            {
                "id": "a",
                "score": 0.5,
                "payload": {
                    "title": "Graph",
                    "content": "Neo4j graph search",
                    "component_type": "chunk",
                    "doc_id": "doc-a",
                },
            },
        ]

        with patch.dict(
            os.environ,
            {
                "RAG_ITERATIVE_RETRIEVAL": "false",
                "RAG_MIN_SCORE": "0.0",
                "RAG_CONTEXT_DOCS": "2",
                "RAG_GENERATE_ANSWER": "false",
            },
            clear=False,
        ), patch.object(query_logic, "QdrantHTTP", lambda: object()), patch.object(
            query_logic, "run_search", lambda **kwargs: list(hits)
        ), patch.object(
            query_logic, "_keyword_fallback_search", lambda *args, **kwargs: []
        ), patch.object(
            query_logic, "build_structural_hits", lambda *args, **kwargs: []
        ), patch.object(
            query_logic, "fetch_related_terms", lambda *args, **kwargs: []
        ):
            result = query_logic.query_documents(
                body,
                rag_service=RagService(),
                get_provider=lambda name: Provider(),
            )

        self.assertTrue(result["ok"])
        self.assertEqual([hit["payload"]["title"] for hit in result["hits"]], ["Graph", "Vector"])
        self.assertEqual(result["retrieval"]["context_docs"], 2)

    def test_query_execution_module_injection_and_flow(self) -> None:
        from pipeline import query_execution

        calls: list[str] = []
        fast_mode_calls: list[bool] = []

        class Provider:
            embed_model = "provider-embed"
            rag_model = "provider-rag"

            def embed(self, text: str) -> list[float]:
                calls.append("embed")
                return [0.1, 0.2, 0.3]

        body = SimpleNamespace(
            query="toy train",
            top_k=2,
            provider="fake",
            filters={"source_format": "markdown"},
            generate=False,
            is_optimized=False,
            fast_mode=False,
            smart_lookup=False,
            structural_hops=1,
            preferred_tags=None,
            reranker="none",
            rerank_top_n=4,
            mix_mode=False,
            mix_weight=0.25,
        )

        result = query_execution.run_query_documents(
            body,
            rag_service=SimpleNamespace(rerank_hits=lambda **kwargs: [{"id": "fallback", "score": 0.1, "payload": {"title": "Fallback", "content": "", "doc_id": "z"}}]),
            get_provider=lambda name: Provider(),
            qdrant_ctor=object,
            analyze_prompt_fn=lambda query: {"blocked": False, "issues": [], "sanitized": query},
            enforce_output_safety_fn=lambda answer: {"blocked": False, "issues": [], "answer": answer},
            sanitize_prompt_text_fn=lambda query: query,
            build_query_rewrite_fn=lambda provider, query, **kwargs: {
                "enabled": True,
                "rewritten_query": "rewritten",
                "high_level_keys": ["toys"],
                "low_level_keys": ["trains"],
                "entity_terms": ["train"],
                "modality_hints": [],
            },
            build_query_terms_fn=lambda rewritten_query, high_level_keys, low_level_keys, entity_terms: ["train", "toy"],
            run_search_fn=lambda **kwargs: [
                {"id": "a", "score": 0.6, "payload": {"title": "A", "component_type": "chunk", "content": "train", "doc_id": "a"}},
                {"id": "b", "score": 0.4, "payload": {"title": "B", "component_type": "chunk", "content": "toy", "doc_id": "b"}},
            ],
            keyword_fallback_fn=lambda *args, **kwargs: [],
            build_structural_hits_fn=lambda *args, **kwargs: [{"id": "s", "payload": {"component_type": "relation", "title": "s"}}],
            structural_hops_fn=lambda: 1,
            structural_limit_fn=lambda top_k: top_k,
            fusion_weights_fn=lambda: (1.0, 0.5),
            rerank_and_filter_hits_fn=lambda hits, **kwargs: hits,
            should_iterate_fn=lambda query, hits, top_k: False,
            collect_expansion_terms_fn=lambda hits: ["x"],
            merge_hits_fn=lambda primary, secondary, limit: secondary + primary,
            build_fused_hits_fn=lambda sem_hits, struct_hits, sem_weight=0.0, str_weight=0.0: sem_hits + struct_hits,
            prepare_context_fn=lambda hits, max_docs, max_tokens: (hits, [], 0),
            run_high_recall_fn=lambda **kwargs: [],
            fetch_related_terms_fn=lambda terms, limit: [{"subject": "HAWKI", "predicate": "related", "object": "RAG"}],
            context_limits_fn=lambda: (400, 10),
            score_thresholds_fn=lambda: (0.0, 0.0),
            iterative_retrieval_enabled_fn=lambda: False,
            generation_enabled_fn=lambda: False,
            configured_search_top_k_fn=lambda top_k: top_k,
            extract_terms_fn=lambda text: text.lower().split(),
            terms_from_payload_fn=lambda payload: ["kg"],
            set_fast_mode_fn=lambda enabled: fast_mode_calls.append(enabled),
        )

        self.assertEqual(result["ok"], True)
        self.assertEqual(len(result["hits"]), 3)
        self.assertEqual(result["count"], 3)
        self.assertTrue(any(h["id"] == "a" for h in result["hits"]))
        self.assertEqual(result["retrieval"]["iterative_pass"], False)
        self.assertIn("embed", calls)
        self.assertEqual(fast_mode_calls, [False])

    def test_query_execution_fast_mode_setter_is_injected(self) -> None:
        from pipeline import query_execution

        body = SimpleNamespace(
            query="fast mode",
            top_k=2,
            provider="fake",
            filters={},
            generate=False,
            is_optimized=False,
            fast_mode=True,
            smart_lookup=False,
            structural_hops=0,
            preferred_tags=None,
            reranker="none",
            rerank_top_n=4,
            mix_mode=False,
            mix_weight=0.25,
        )

        fast_mode_calls: list[bool] = []
        query_execution.run_query_documents(
            body,
            rag_service=SimpleNamespace(rerank_hits=lambda **kwargs: []),
            get_provider=lambda name: SimpleNamespace(
                embed=lambda text: [0.1, 0.2, 0.3],
                embed_model="embed-model",
                rag_model="rag-model",
            ),
            qdrant_ctor=object,
            analyze_prompt_fn=lambda query: {"blocked": False, "issues": [], "sanitized": query},
            enforce_output_safety_fn=lambda answer: {"blocked": False, "issues": [], "answer": answer},
            sanitize_prompt_text_fn=lambda query: query,
            build_query_rewrite_fn=lambda provider, query, **kwargs: {
                "enabled": False,
                "rewritten_query": query,
                "high_level_keys": [],
                "low_level_keys": [],
                "entity_terms": [],
                "modality_hints": [],
            },
            build_query_terms_fn=lambda rewritten_query, high_level_keys, low_level_keys, entity_terms: [],
            run_search_fn=lambda **kwargs: [],
            keyword_fallback_fn=lambda *args, **kwargs: [],
            build_structural_hits_fn=lambda *args, **kwargs: [],
            structural_hops_fn=lambda: 0,
            structural_limit_fn=lambda top_k: top_k,
            rerank_and_filter_hits_fn=lambda hits, **kwargs: hits,
            run_high_recall_fn=lambda **kwargs: [],
            fetch_related_terms_fn=lambda terms, limit: [],
            set_fast_mode_fn=lambda enabled: fast_mode_calls.append(enabled),
        )

        self.assertEqual(fast_mode_calls, [True])

    def test_query_stages_rewrite_contract_is_multimodal_and_dedupe_terms(self) -> None:
        from pipeline import query_stages

        with patch.object(
            query_stages,
            "_is_multimodal_query",
            return_value=True,
        ) as multimodal_check, patch.object(
            query_stages,
            "_rewrite_query",
            return_value={
                "rewritten_query": "Compare wooden blocks and toy trains",
                "high_level_keys": ["toys", "toys"],
                "low_level_keys": ["blocks", ""],
                "modality_hints": ["image", None],
                "entity_terms": ["train", "train"],
            },
        ):
            rewrite = query_stages.build_query_rewrite(
                SimpleNamespace(chat=lambda system, messages: "{}"),
                "How to show toy train figure?",
                fast_mode=False,
            )

        self.assertEqual(rewrite["enabled"], True)
        self.assertEqual(rewrite["high_level_keys"], ["toys", "toys"])
        self.assertEqual(rewrite["low_level_keys"], ["blocks"])
        self.assertEqual(rewrite["modality_hints"], ["image"])
        self.assertEqual(rewrite["entity_terms"], ["train", "train"])

        with patch.object(query_stages, "_extract_terms", return_value=["compare"]):
            query_terms = query_stages.build_query_terms(
                "Compare wooden blocks and toy trains",
                rewrite["high_level_keys"],
                rewrite["low_level_keys"],
                rewrite["entity_terms"],
            )

        self.assertEqual(query_terms, ["train", "blocks", "toys", "compare"])
        multimodal_check.assert_called_once()

    def test_query_stages_rerank_and_filter_preserves_best_path_and_fallback(self) -> None:
        from pipeline import query_stages

        class RerankService:
            def __init__(self) -> None:
                self.calls = 0

            def rerank_hits(self, **kwargs) -> list[dict[str, object]]:
                self.calls += 1
                return [
                    {"id": "a", "score": 0.2, "payload": {"title": "Alpha"}},
                    {"id": "b", "score": 0.6, "payload": {"title": "Beta"}},
                    {"id": "a", "score": 0.2, "payload": {"title": "Alpha"}},
                ]

        rerank_service = RerankService()
        hits = [
            {"id": "a", "score": 0.2, "payload": {"title": "Alpha"}},
            {"id": "b", "score": 0.6, "payload": {"title": "Beta"}},
            {"id": "c", "score": 0.4, "payload": {"title": "Gamma"}},
        ]
        provider = SimpleNamespace(embed_model="toy", rag_model="rag")

        with patch.object(query_stages, "apply_lexical_boost", return_value=[]):
            no_match = query_stages.rerank_and_filter_hits(
                hits,
                user_query="unrelated",
                provider=provider,
                query_vector=[0.1],
                rag_service=rerank_service,
                mode="none",
                top_n=12,
                mix_mode=False,
                mix_weight=0.4,
                min_score=0.5,
                fallback_min=0.3,
                top_k=2,
            )

        self.assertEqual([item["id"] for item in no_match], ["b"])
        self.assertEqual(rerank_service.calls, 1)

        with patch.object(query_stages, "apply_lexical_boost", return_value=[]), patch.object(
            query_stages,
            "_extract_terms",
            return_value=["compare"],
        ):
            fallback = query_stages.filter_hits_by_score(
                hits,
                query="unrelated",
                min_score=0.9,
                fallback_min=0.9,
                top_k=2,
            )

        self.assertEqual([item["id"] for item in fallback], ["a", "b"])


    def test_query_rewrite_module_handles_injected_policy_dependencies(self) -> None:
        from pipeline import query_rewrite

        rewrite = query_rewrite.build_query_rewrite(
            SimpleNamespace(chat=lambda system, messages: "{}"),
            "Show toy planes and trains",
            fast_mode=False,
            is_multimodal_query=lambda text: True,
            rewrite_query=lambda provider, text: {
                "rewritten_query": "visual toy planes",
                "high_level_keys": ["toys", "", None, "toys"],
                "low_level_keys": ["planes", None],
                "modality_hints": [None, "image"],
                "entity_terms": ["train", "train"],
            },
            normalize_list=lambda values: [v for v in (values or []) if v],
        )

        self.assertEqual(
            rewrite["high_level_keys"],
            ["toys", "toys"],
        )
        self.assertEqual(rewrite["low_level_keys"], ["planes"])
        self.assertEqual(rewrite["modality_hints"], ["image"])
        self.assertEqual(rewrite["entity_terms"], ["train", "train"])

        terms = query_rewrite.build_query_terms(
            "Visual toy planes",
            rewrite["high_level_keys"],
            rewrite["low_level_keys"],
            rewrite["entity_terms"],
            extract_terms=lambda query: ["visual", "train", "train"],
        )
        self.assertEqual(terms, ["train", "planes", "toys", "visual"])

    def test_query_ranking_module_iterate_and_expansion_terms(self) -> None:
        from pipeline import query_ranking

        class RerankService:
            def rerank_hits(self, **kwargs) -> list[dict[str, object]]:
                return [
                    {"id": "b", "score": 0.8, "payload": {"title": "Beta"}},
                    {"id": "a", "score": 0.2, "payload": {"title": "Alpha"}},
                ]

        reranked = query_ranking.rerank_and_filter_hits(
            [{"id": "x", "score": 0.5}],
            user_query="toy train",
            provider=SimpleNamespace(),
            query_vector=[0.1],
            rag_service=RerankService(),
            mode="none",
            top_n=5,
            mix_mode=False,
            mix_weight=0.5,
            min_score=0.7,
            fallback_min=0.7,
            top_k=1,
            filter_hits=lambda hits, **kwargs: hits,
        )
        self.assertEqual(
            reranked,
            [
                {"id": "b", "score": 0.8, "payload": {"title": "Beta"}},
                {"id": "a", "score": 0.2, "payload": {"title": "Alpha"}},
            ],
        )

        self.assertTrue(query_ranking.should_iterate("then compare toy trains", [{"score": 0.8}], top_k=3))
        self.assertFalse(query_ranking.should_iterate("toy blocks", [{"score": 0.9}, {"score": 0.8}, {"score": 0.7}], top_k=3))

        expansion = query_ranking.collect_expansion_terms(
            [
                {"payload": {"content": "blocks for kids"}},
                {"payload": {"content": "builds and more"}},
            ],
            limit=2,
            extract_terms=lambda text: ["blocks", "blocks", "toys", ""],
        )
        self.assertEqual(expansion, ["blocks", "toys"])


class CliIngestHelperCharacterizationTests(unittest.TestCase):
    def test_ingest_metadata_helpers_normalize_values_and_make_stable_doc_ids(self) -> None:
        from ingest.metadata import (
            first_str,
            make_doc_id,
            resolve_date,
            title_from_markdown,
            to_array_list,
        )

        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "content.md"
            path.write_text("# Wooden Toys\nBody", encoding="utf-8")
            resolved_date = resolve_date({}, path)

        self.assertIsNone(first_str(" null "))
        self.assertEqual(first_str([" Toys ", "Other"]), "Toys")
        self.assertEqual(to_array_list(["A", None, "undefined", "B"]), ["A", "B"])
        self.assertEqual(title_from_markdown("\n# Wooden Toys\nBody"), "Wooden Toys")
        self.assertRegex(resolved_date or "", r"^\d{4}-\d{2}-\d{2}T")
        self.assertEqual(make_doc_id("upload://toys.md", "toys/content.md"), make_doc_id("upload://toys.md", "other"))

    def test_ingest_payload_builder_preserves_bridge_document_contract(self) -> None:
        from ingest.payloads import build_bridge_doc, build_payload

        payload = build_payload(
            meta={
                "canonical_url": "https://example.test/toys",
                "updatedAt": "2026-06-10T07:00:00Z",
                "fetchTime": "2026-06-10T07:01:00Z",
                "http_status": 200,
            },
            title="Toy Catalog",
            page_url="https://example.test/page",
            source_url=None,
            rel_path="toys/content.md",
            date="2026-06-10",
            meta_img=None,
            meta_img_list=[],
            images_list=["image.png"],
            pdfs_list=["manual.pdf"],
            tags=[],
            source_format="markdown",
            md_path=Path("/tmp/toys/content.md"),
            ingested_at="2026-06-10T08:00:00Z",
        )
        doc = build_bridge_doc(doc_id="doc-1", text="Toy content", payload=payload)

        self.assertEqual(payload["title"], "Toy Catalog")
        self.assertEqual(payload["source_url"], "https://example.test/page")
        self.assertEqual(payload["tags"], None)
        self.assertEqual(payload["updated_at"], "2026-06-10T07:00:00Z")
        self.assertEqual(payload["fetch_time"], "2026-06-10T07:01:00Z")
        self.assertEqual(payload["file_path"], "/tmp/toys/content.md")
        self.assertEqual(doc, {"id": "doc-1", "text": "Toy content", "payload": payload})

    def test_ingest_resume_helpers_batch_retry_and_persist_state(self) -> None:
        from ingest.resume import (
            batched,
            load_resume_state,
            safe_state_filename,
            save_resume_state_payload,
            should_split_batch,
        )

        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / safe_state_filename("toy_docs::/tmp/root")
            save_resume_state_payload(
                path,
                doc_ids={"doc-b", "doc-a"},
                metadata={"collection": "toy_docs"},
                updated_at="2026-06-10T08:00:00Z",
            )
            data = json.loads(path.read_text(encoding="utf-8"))

            self.assertEqual(load_resume_state(path), {"doc-a", "doc-b"})
            self.assertEqual(data["doc_ids"], ["doc-a", "doc-b"])
            self.assertEqual(data["collection"], "toy_docs")

        self.assertRegex(safe_state_filename("toy_docs"), r"^[0-9a-f]{40}\.json$")
        self.assertEqual(list(batched([1, 2, 3, 4, 5], 2)), [[1, 2], [3, 4], [5]])
        self.assertTrue(should_split_batch("HTTP 504 gateway timeout"))
        self.assertFalse(should_split_batch("HTTP 400 bad request"))

    def test_ingest_url_maps_link_converted_outputs_to_source_pdf_urls(self) -> None:
        from ingest.url_maps import build_url_maps, resolve_url_for_path

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            page = root / "page"
            pdf = page / "files" / "manual.pdf"
            converted = root / "converted"
            output = converted / "output"
            pdf.parent.mkdir(parents=True)
            output.mkdir(parents=True)
            pdf.write_text("pdf", encoding="utf-8")
            (page / "page.json").write_text(
                json.dumps(
                    {
                        "page_url": "https://example.test/toys",
                        "pdfs": [{"local_path": str(pdf), "url": "https://example.test/manual.pdf"}],
                    }
                ),
                encoding="utf-8",
            )
            (converted / "conversion_meta.json").write_text(
                json.dumps({"source_pdf": str(pdf), "output_dir": str(converted)}),
                encoding="utf-8",
            )

            page_map, source_map = build_url_maps(root)

            self.assertEqual(resolve_url_for_path(page_map, output, root), "https://example.test/toys")
            self.assertEqual(resolve_url_for_path(source_map, output, root), "https://example.test/manual.pdf")

    def test_pdf_link_extraction_dedupes_and_strips_trailing_punctuation(self) -> None:
        from ingest.links import extract_pdf_links

        text = (
            "Read https://example.test/a.pdf, then "
            "https://example.test/a.pdf) and https://example.test/b.PDF."
        )

        self.assertEqual(
            extract_pdf_links(text),
            ["https://example.test/a.pdf", "https://example.test/b.PDF"],
        )

    def test_discover_page_dirs_treats_converted_folders_as_document_units(self) -> None:
        from ingest.discovery import discover_page_dirs

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            page = root / "page"
            converted = page / "converted_doc"
            nested = converted / "output"
            ordinary = root / "ordinary"
            nested.mkdir(parents=True)
            ordinary.mkdir()
            (page / "page.json").write_text("{}", encoding="utf-8")
            (converted / "conversion_meta.json").write_text("{}", encoding="utf-8")
            (nested / "content.md").write_text("duplicate", encoding="utf-8")
            (ordinary / "content.md").write_text("ok", encoding="utf-8")

            discovered = [path.relative_to(root) for path in discover_page_dirs(root)]

        self.assertEqual(
            set(discovered),
            {Path("page"), Path("page/converted_doc"), Path("ordinary")},
        )


class IngestDeletionCharacterizationTests(unittest.TestCase):
    def test_delete_document_entries_deletes_vector_and_graph_then_closes_graph(self) -> None:
        from pipeline.ingest.deletion import delete_document_entries

        events: list[tuple[str, str]] = []

        class Qdrant:
            def delete_by_doc_id(self, doc_id: str) -> dict[str, str]:
                events.append(("qdrant", doc_id))
                return {"deleted": doc_id}

        class Graph:
            def delete_by_doc_id(self, doc_id: str) -> dict[str, str]:
                events.append(("graph", doc_id))
                return {"deleted": doc_id}

            def close(self) -> None:
                events.append(("graph_close", ""))

        result = delete_document_entries(
            "doc-1",
            qdrant_factory=Qdrant,
            graph_factory=Graph,
        )

        self.assertEqual(result, {"qdrant": {"deleted": "doc-1"}, "neo4j": {"deleted": "doc-1"}})
        self.assertEqual(events, [("qdrant", "doc-1"), ("graph", "doc-1"), ("graph_close", "")])


class ApiAndVectorValidationTests(unittest.TestCase):
    def test_api_schema_defaults_and_provider_errors_are_validation_boundaries(self) -> None:
        from fastapi import HTTPException

        from app.dependencies import get_provider_or_400
        from app.schemas import IngestDoc, IngestRequest, QueryRequest, apply_ingest_request_settings, apply_query_request_settings
        from app.settings import load_app_settings

        ingest = IngestRequest(
            docs=[IngestDoc(id="doc-1", text="Toy catalog", payload={"title": "Toys"})],
        )
        query = QueryRequest(query="Which toys are wooden?")
        settings = load_app_settings()

        self.assertEqual(ingest.provider, settings.rag_default_provider)
        self.assertEqual(ingest.distance, settings.qdrant_distance)
        self.assertEqual(query.top_k, 5)
        self.assertEqual(query.filters, {})
        self.assertEqual(apply_ingest_request_settings(ingest, settings), ingest)

        patched_query = apply_query_request_settings(query, settings)
        self.assertEqual(patched_query.provider, settings.rag_default_provider)
        self.assertEqual(patched_query.reranker, settings.reranker_mode)
        self.assertEqual(patched_query.mix_mode, settings.reranker_mix_mode)
        self.assertEqual(patched_query.mix_weight, settings.reranker_mix_weight)

        custom_query = QueryRequest(
            query="Which toys are wooden?",
            provider="query-provider",
            reranker="cosine",
            mix_mode=False,
        )
        patched = apply_query_request_settings(custom_query, settings)
        self.assertEqual(patched.provider, "query-provider")
        self.assertEqual(patched.reranker, "cosine")
        self.assertFalse(patched.mix_mode)

        custom_ingest = IngestRequest(
            docs=[IngestDoc(id="doc-1", text="x", payload={})],
            provider="ingest-provider",
            distance="L2",
            chunk_chars=999,
            chunk_overlap=88,
            batch_size=12,
            graph_engine="custom-graph",
        )
        patched_ingest = apply_ingest_request_settings(custom_ingest, settings)
        self.assertEqual(patched_ingest.provider, "ingest-provider")
        self.assertEqual(patched_ingest.distance, "L2")
        self.assertEqual(patched_ingest.chunk_chars, 999)
        self.assertEqual(patched_ingest.batch_size, 12)
        self.assertEqual(patched_ingest.graph_engine, "custom-graph")

    def test_app_settings_includes_runtime_env_overrides(self) -> None:
        from fastapi import HTTPException

        from app.dependencies import get_provider_or_400
        from app.settings import load_app_settings

        with patch.dict(
            os.environ,
            {
                "CUDA_VISIBLE_DEVICES": "0,1",
                "NVIDIA_VISIBLE_DEVICES": "GPU-7",
            },
            clear=False,
        ):
            settings = load_app_settings()

        self.assertEqual(settings.cuda_visible_devices, "0,1")
        self.assertEqual(settings.nvidia_visible_devices, "GPU-7")

        class Service:
            def get_provider(self, name: str):
                raise ValueError(f"unknown provider {name}")

        with self.assertRaises(HTTPException) as raised:
            get_provider_or_400(Service(), "missing")

        self.assertEqual(raised.exception.status_code, 400)
        self.assertIn("unknown provider missing", raised.exception.detail)

    def test_document_replacement_request_validates_text_and_preserves_defaults(self) -> None:
        from fastapi import HTTPException

        from app.documents import build_replacement_ingest_request
        from app.schemas import DocumentUpsertRequest
        from app.settings import load_app_settings

        with self.assertRaises(HTTPException) as raised:
            build_replacement_ingest_request(
                doc_id="doc-1",
                body=DocumentUpsertRequest(text=" "),
                app_settings=load_app_settings(),
            )
        self.assertEqual(raised.exception.status_code, 400)

        request = build_replacement_ingest_request(
            doc_id="doc-1",
            body=DocumentUpsertRequest(
                text="Toy blocks",
                payload={"title": "Toys"},
                provider="fake",
                collection="toy_docs",
                graph=True,
            ),
            app_settings=load_app_settings(),
        )

        self.assertEqual(request.docs[0].id, "doc-1")
        self.assertEqual(request.docs[0].payload, {"title": "Toys"})
        self.assertEqual(request.provider, "fake")
        self.assertEqual(request.collection, "toy_docs")
        self.assertEqual(request.chunk_chars, 3200)
        self.assertEqual(request.chunk_overlap, 250)
        self.assertTrue(request.graph)

    def test_config_response_uses_provider_and_qdrant_boundaries(self) -> None:
        from app.config_response import build_config_response
        from app.settings import AppSettings

        class Provider:
            embed_model = "embed-toys"

        class Qdrant:
            collection = "toy_docs"

            def get_vector_size(self) -> int:
                return 384

        with patch.dict(
            os.environ,
            {
                "RAG_DEFAULT_PROVIDER": "fake",
                "RERANKER_MODE": "none",
                "RERANKER_MIX_MODE": "true",
                "RERANKER_MIX_WEIGHT": "0.7",
            },
            clear=False,
        ):
            response = build_config_response(
                get_provider=lambda name: Provider(),
                qdrant_factory=Qdrant,
                app_settings=AppSettings(
                    rag_default_provider="fake",
                    qdrant_distance="Cosine",
                    graph_engine="raganything",
                    reranker_mode="none",
                    reranker_mix_mode=True,
                    reranker_mix_weight=0.7,
                    reranker_jina_model="jina-reranker-v2-base-multilingual",
                    reranker_api_url="",
                    chunk_size=1200,
                    chunk_overlap_size=250,
                    ingest_batch_size=64,
                    cuda_visible_devices="unset",
                    nvidia_visible_devices="unset",
                    log_level="INFO",
                    graph_debug=False,
                    graph_debug_log="",
                    public_dir=Path("/tmp"),
                ),
            )

        self.assertEqual(response["provider"], "fake")
        self.assertEqual(response["embedding_model"], "embed-toys")
        self.assertEqual(response["qdrant_collection"], "toy_docs")
        self.assertEqual(response["qdrant_vector_size"], 384)
        self.assertEqual(response["reranker"]["mix_weight"], 0.7)

    def test_app_logging_config_sets_app_and_graph_logger_levels(self) -> None:
        import logging

        from app.logging_config import configure_app_logging, env_flag
        from app.settings import AppSettings

        app_logger = logging.getLogger("tests.logging_config")
        ingest_logger = logging.getLogger("pipeline.ingest_logic")
        rag_logger = logging.getLogger("core.rag_service")
        old_levels = (app_logger.level, ingest_logger.level, rag_logger.level)
        try:
            logger, graph_debug, graph_debug_log = configure_app_logging(
                AppSettings(
                    rag_default_provider="ollama",
                    qdrant_distance="Cosine",
                    graph_engine="raganything",
                    reranker_mode="none",
                    reranker_mix_mode=False,
                    reranker_mix_weight=0.5,
                    reranker_jina_model="jina-reranker-v2-base-multilingual",
                    reranker_api_url="",
                    chunk_size=1200,
                    chunk_overlap_size=250,
                    ingest_batch_size=64,
                    cuda_visible_devices="unset",
                    nvidia_visible_devices="unset",
                    log_level="WARNING",
                    graph_debug=True,
                    graph_debug_log="",
                    public_dir=Path("/tmp"),
                ),
                logger_name="tests.logging_config",
            )

            self.assertTrue(env_flag("yes"))
            self.assertFalse(env_flag(""))
            self.assertTrue(graph_debug)
            self.assertEqual(graph_debug_log, "")
            self.assertEqual(logger.level, logging.WARNING)
            self.assertEqual(ingest_logger.level, logging.DEBUG)
        finally:
            app_logger.setLevel(old_levels[0])
            ingest_logger.setLevel(old_levels[1])
            rag_logger.setLevel(old_levels[2])

    def test_app_router_builder_surface_exists_and_routes_are_available(self) -> None:
        import sys

        with tempfile.TemporaryDirectory() as tmp:
            with patch.dict(os.environ, {"RAG_WORKING_DIR": tmp}, clear=False):
                for mod_name in [
                    "app.main",
                    "app.routers",
                    "app.routers.health",
                    "app.routers.config",
                    "app.routers.ingest",
                    "app.routers.query",
                    "app.routers.graph",
                ]:
                    sys.modules.pop(mod_name, None)

                app_main = importlib.import_module("app.main")
                paths = {route.path for route in app_main.app.router.routes}

        self.assertIn("/health", paths)
        self.assertIn("/config", paths)
        self.assertIn("/ingest", paths)
        self.assertIn("/documents/{doc_id}", paths)
        self.assertIn("/query", paths)
        self.assertIn("/graph/from-text", paths)
        self.assertIn("/graph/cache/clear", paths)

    def test_app_factory_builds_routes_with_injected_dependencies(self) -> None:
        from app.factory import build_app
        from app.settings import load_app_settings

        class FakeService:
            def __init__(self) -> None:
                self.runtime_calls = 0
                self.clear_calls = 0
                self.provider_calls = 0

            def graph_runtime_summary(self) -> dict[str, object]:
                self.runtime_calls += 1
                return {"mode": "test"}

            def clear_graph_cache(self) -> dict[str, object]:
                self.clear_calls += 1
                return {"ok": True}

            def get_provider(self, name: str) -> object:
                self.provider_calls += 1
                return SimpleNamespace(embed_model="test-embed")

        class FakeQdrant:
            collection = "app_test"

            def get_vector_size(self) -> int:
                return 128

        with tempfile.TemporaryDirectory() as tmp:
            app_settings = load_app_settings()
            service = FakeService()
            app = build_app(
                rag_service=service,
                public_dir=Path(tmp),
                qdrant_factory=FakeQdrant,
                logger_name="app_test_factory",
                app_settings=app_settings,
            )

            with TestClient(app) as client:
                health = client.get("/health").json()
                config = client.get("/config").json()
                cache = client.post("/graph/cache/clear").json()

        self.assertEqual(health["ok"], True)
        self.assertEqual(health["runtime"], {"mode": "test"})
        self.assertEqual(config["provider"], app_settings.rag_default_provider)
        self.assertEqual(config["qdrant_collection"], "app_test")
        self.assertEqual(config["qdrant_vector_size"], 128)
        self.assertEqual(cache["ok"], True)
        self.assertEqual(service.runtime_calls, 1)
        self.assertEqual(service.provider_calls, 1)
        self.assertEqual(service.clear_calls, 1)

    def test_app_query_route_uses_injected_dependencies(self) -> None:
        from app.factory import build_app
        from app.schemas import QueryRequest

        class FakeService:
            def __init__(self) -> None:
                self.provider_calls: list[str] = []

            def get_provider(self, name: str) -> object:
                self.provider_calls.append(name)
                return SimpleNamespace(embed_model="query-embed", rag_model="query-rag")

        query_body = QueryRequest(
            query="Why wooden toys are safe?",
            top_k=4,
            provider="query-provider",
            filters={"source_format": "markdown"},
            generate=False,
            is_optimized=True,
            fast_mode=True,
            smart_lookup=True,
            structural_hops=2,
            preferred_tags=["kids", "safety"],
            reranker="cosine",
            rerank_top_n=12,
            mix_mode=False,
            mix_weight=0.4,
        )
        captured: dict[str, object] = {}

        def fake_query_documents(
            body: QueryRequest,
            rag_service: object,
            get_provider,
        ) -> dict[str, object]:
            captured["body_type"] = type(body).__name__
            captured["body_provider"] = body.provider
            captured["body_top_k"] = body.top_k
            captured["service_is_injected"] = rag_service is service
            captured["provider_fn_called"] = callable(get_provider)
            captured["provider_value"] = get_provider(body.provider)
            return {
                "ok": True,
                "query": body.query,
                "top_k": body.top_k,
            }

        with tempfile.TemporaryDirectory() as tmp:
            service = FakeService()
            app = build_app(
                rag_service=service,
                public_dir=Path(tmp),
                qdrant_factory=object,
                logger_name="app_test_query_route",
            )

            with patch("app.query.query_documents", side_effect=fake_query_documents):
                with TestClient(app) as client:
                    response = client.post(
                        "/query",
                        json=query_body.model_dump(),
                    )

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json(), {"ok": True, "query": query_body.query, "top_k": 4})
        self.assertEqual(captured["body_type"], "QueryRequest")
        self.assertEqual(captured["body_provider"], query_body.provider)
        self.assertEqual(captured["body_top_k"], 4)
        self.assertEqual(captured["provider_fn_called"], True)
        self.assertEqual(captured["service_is_injected"], True)
        self.assertEqual(captured["provider_value"].embed_model, "query-embed")
        self.assertEqual(service.provider_calls, [query_body.provider])

    def test_app_ingest_route_delegates_with_injected_dependencies(self) -> None:
        from app.factory import build_app
        from app.schemas import IngestDoc, IngestRequest

        class FakeService:
            def __init__(self) -> None:
                self.provider_calls: list[str] = []

            def get_provider(self, name: str) -> object:
                self.provider_calls.append(name)
                return SimpleNamespace(embed_model="ingest-embed", rag_model="graph-rag")

        ingest_body = IngestRequest(
            docs=[IngestDoc(id="doc-toy-1", text="Wooden trains and blocks.", payload={"title": "Toys"})],
            provider="ingest-provider",
            collection="toy_docs",
            graph=True,
        )
        captured: dict[str, object] = {}

        def fake_ingest_documents(
            body: IngestRequest,
            rag_service: object,
            get_provider,
            public_dir,
            **kwargs: object,
        ) -> dict[str, object]:
            captured["docs_len"] = len(body.docs)
            captured["provider_arg"] = body.provider
            captured["service_is_injected"] = rag_service is service
            captured["public_dir_path"] = str(public_dir)
            captured["provider_fn_called"] = callable(get_provider)
            captured["provider_fn_value"] = get_provider(body.provider)
            captured["idempotency_key"] = kwargs.get("idempotency_key")
            return {
                "ok": True,
                "collection": body.collection,
                "count": len(body.docs),
            }

        with tempfile.TemporaryDirectory() as tmp:
            service = FakeService()
            app = build_app(
                rag_service=service,
                public_dir=Path(tmp),
                qdrant_factory=object,
                logger_name="app_test_ingest_route",
            )

                with patch("app.ingest.ingest_documents", side_effect=fake_ingest_documents):
                    with TestClient(app) as client:
                        response = client.post(
                            "/ingest",
                            headers={"Idempotency-Key": "ingest-route-key"},
                            json=ingest_body.model_dump(),
                        )

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json(), {"ok": True, "collection": "toy_docs", "count": 1})
        self.assertEqual(captured["docs_len"], 1)
        self.assertEqual(captured["provider_arg"], "ingest-provider")
        self.assertEqual(captured["service_is_injected"], True)
        self.assertEqual(captured["public_dir_path"], tmp)
        self.assertEqual(captured["provider_fn_called"], True)
        self.assertEqual(captured["provider_fn_value"].embed_model, "ingest-embed")
        self.assertEqual(captured["idempotency_key"], "ingest-route-key")
        self.assertEqual(service.provider_calls, [ingest_body.provider])

    def test_app_document_routes_replace_and_delete_contract(self) -> None:
        from app.factory import build_app

        class FakeService:
            def get_provider(self, name: str) -> object:
                return SimpleNamespace(embed_model="ingest-embed")

        with tempfile.TemporaryDirectory() as tmp:
            app = build_app(
                rag_service=FakeService(),
                public_dir=Path(tmp),
                qdrant_factory=object,
                logger_name="app_test_documents_routes",
            )

            with patch(
                "app.documents.build_replacement_ingest_request",
                return_value=SimpleNamespace(
                    docs=[SimpleNamespace(id="doc-replace-1", text="replacement", payload={})],
                    provider="fake",
                    collection="toy_docs",
                    graph=False,
                    graph_engine="raganything",
                    chunk_chars=1200,
                    chunk_overlap=250,
                    dry_run=False,
                    dry_include_graph=False,
                ),
            ) as replacement_builder, patch(
                "app.ingest.delete_document",
                return_value={"qdrant": {"ok": True}, "neo4j": {"ok": True}},
            ) as delete_mock, patch(
                "app.ingest.ingest_documents",
                return_value={"ok": True},
            ) as ingest_mock:
                with TestClient(app) as client:
                    delete_response = client.delete("/documents/doc-replace-1")
                    put_response = client.put(
                        "/documents/doc-replace-1",
                        json={"text": "updated"},
                    )

        self.assertEqual(delete_response.status_code, 200)
        self.assertEqual(
            delete_response.json(),
            {"ok": True, "doc_id": "doc-replace-1", "qdrant": {"ok": True}, "neo4j": {"ok": True}},
        )
        self.assertEqual(put_response.status_code, 200)
        self.assertEqual(put_response.json()["ok"], True)
        self.assertEqual(put_response.json()["replaced_doc_id"], "doc-replace-1")
        self.assertEqual(put_response.json()["deleted"], {"qdrant": {"ok": True}, "neo4j": {"ok": True}})
        self.assertEqual(put_response.json(), {"ok": True, "replaced_doc_id": "doc-replace-1", "deleted": {"qdrant": {"ok": True}, "neo4j": {"ok": True}}})
        self.assertEqual(delete_mock.call_count, 2)
        delete_calls = delete_mock.call_args_list
        self.assertEqual(delete_calls[0].args[0], "doc-replace-1")
        self.assertEqual(delete_calls[0].kwargs["idempotency_key"], "doc-replace-1")
        self.assertEqual(delete_calls[1].args[0], "doc-replace-1")
        self.assertIsNone(delete_calls[1].kwargs["idempotency_key"])
        replacement_builder.assert_called_once()
        ingest_mock.assert_called_once()

    def test_qdrant_payload_helpers_build_expected_filters_and_batches(self) -> None:
        from vectorstore.payloads import (
            build_delete_filter,
            build_search_body,
            build_text_filter,
            iter_batches,
        )

        self.assertEqual(
            build_delete_filter("doc-1"),
            {"must": [{"key": "doc_id", "match": {"value": "doc-1"}}]},
        )
        self.assertEqual(list(iter_batches([1, 2, 3, 4, 5], 2)), [[1, 2], [3, 4], [5]])
        self.assertEqual(
            build_text_filter(["wooden", "", "blocks"], ["content", "title"], max_terms=2, require_all=True),
            {
                "must": [
                    {
                        "should": [
                            {"key": "content", "match": {"text": "wooden"}},
                            {"key": "title", "match": {"text": "wooden"}},
                        ]
                    },
                    {
                        "should": [
                            {"key": "content", "match": {"text": "blocks"}},
                            {"key": "title", "match": {"text": "blocks"}},
                        ]
                    },
                ]
            },
        )
        self.assertEqual(
            build_search_body(
                [0.1, 0.2],
                top_k=3,
                filters={"source_format": "markdown"},
                with_payload=True,
                with_vector=False,
                keyword_terms=["toys"],
                keyword_fields=["content"],
                payload_projection=["title"],
            ),
            {
                "vector": [0.1, 0.2],
                "limit": 3,
                "with_payload": {"include": ["title"]},
                "with_vector": False,
                "filter": {
                    "must": [{"key": "source_format", "match": {"value": "markdown"}}],
                    "should": [{"key": "content", "match": {"value": "toys"}}],
                },
            },
        )

    def test_qdrant_settings_parse_env_and_fall_back_on_invalid_numbers(self) -> None:
        from vectorstore.settings import qdrant_settings_from_env

        with patch.dict(
            os.environ,
            {
                "QDRANT_SCHEME": "https",
                "QDRANT_HOST": "qdrant.local",
                "QDRANT_PORT": "bad",
                "QDRANT_COLLECTION": " toy_docs ",
                "QDRANT_API_KEY": "secret",
                "QDRANT_TIMEOUT": "bad",
                "QDRANT_RETRY_ATTEMPTS": "5",
            },
            clear=False,
        ):
            settings = qdrant_settings_from_env()

        self.assertEqual(settings.base_url, "https://qdrant.local:6333")
        self.assertEqual(settings.collection, "toy_docs")
        self.assertEqual(settings.api_key, "secret")
        self.assertEqual(settings.timeout, 30.0)
        self.assertEqual(settings.max_attempts, 5)

    def test_qdrant_http_settings_parse_and_defaults(self) -> None:
        from vectorstore.settings import qdrant_http_settings_from_env

        with patch.dict(
            os.environ,
            {
                "QDRANT_LOG_LATENCY": "yes",
                "QDRANT_SEARCH_ALL": "1",
                "QDRANT_FALLBACK_ALL": "0",
                "QDRANT_UPSERT_TIMEOUT": "bad",
                "QDRANT_SEARCH_TIMEOUT": "bad",
                "QDRANT_TEXT_FALLBACK_TERMS": "7",
                "QDRANT_TEXT_SCROLL_HARD_CAP": "bad",
            },
            clear=False,
        ):
            settings = qdrant_http_settings_from_env(base_timeout=12.5)

        self.assertTrue(settings.log_latency)
        self.assertTrue(settings.search_all)
        self.assertFalse(settings.fallback_all)
        self.assertEqual(settings.search_all_per_collection, 0)
        self.assertEqual(settings.fallback_per_collection, 0)
        self.assertEqual(settings.upsert_timeout, 12.5)
        self.assertEqual(settings.search_timeout, 12.5)
        self.assertEqual(settings.text_fallback_terms, 7)
        self.assertEqual(settings.text_scroll_hard_cap, 50000)

    def test_qdrant_http_uses_injected_http_settings_for_timeouts(self) -> None:
        from vectorstore.qdrant_http import QdrantHTTP
        from vectorstore.settings import QdrantHTTPSettings, QdrantSettings

        requests: list[tuple[str, str, dict[str, object]]] = []

        class FakeResponse:
            status_code = 200

            def raise_for_status(self) -> None:
                return None

            def json(self):
                return {"result": [{"id": "sample", "score": 0.9}]}

        class FakeSession:
            def request(self, method: str, url: str, **kwargs: object) -> FakeResponse:
                requests.append((method, url, dict(kwargs)))
                return FakeResponse()

        with patch("vectorstore.qdrant_http.requests.Session", return_value=FakeSession()):
            client = QdrantHTTP(
                settings=QdrantSettings(
                    scheme="http",
                    host="qdrant-host",
                    port=6333,
                    collection="toy_collection",
                    api_key="",
                    timeout=3.0,
                    max_attempts=1,
                ),
                http_settings=QdrantHTTPSettings(
                    log_latency=False,
                    search_all=False,
                    search_all_per_collection=0,
                    fallback_all=True,
                    fallback_per_collection=0,
                    upsert_timeout=9.0,
                    search_timeout=8.0,
                    count_timeout=7.0,
                    delete_timeout=6.0,
                    text_timeout=5.0,
                    text_fallback_terms=2,
                    text_scroll_hard_cap=500,
                    text_scroll_batch=32,
                ),
            )

            self.assertEqual(client.upsert([{"id": "1", "vector": [1.0], "payload": {}}]), None)

        self.assertEqual(len(requests), 1)
        method, url, kwargs = requests[0]
        self.assertEqual(method, "PUT")
        self.assertEqual(url, "http://qdrant-host:6333/collections/toy_collection/points")
        self.assertEqual(kwargs["timeout"], 9.0)

    def test_qdrant_http_delegates_requests_to_primitive_gateway(self) -> None:
        from vectorstore.qdrant_http import QdrantHTTP
        from vectorstore.settings import QdrantHTTPSettings, QdrantSettings

        calls: list[tuple[str, object]] = []

        class FakeResponse:
            status_code = 200

            def __init__(self, payload: object) -> None:
                self._payload = payload
                self.text = ""

            def raise_for_status(self) -> None:
                return None

            def json(self):
                return self._payload

        class FakeGateway:
            def get_collection(self):
                calls.append(("get_collection", self))
                return FakeResponse({"result": {"status": "ok"}})

            def list_collections(self):
                calls.append(("list_collections", self))
                return FakeResponse({"result": {"collections": []}})

            def search(self, collection, body, timeout):
                calls.append(("search", collection, timeout))
                return FakeResponse({"result": [{"id": "1", "score": 0.4}]})

            def upsert(self, points, timeout):
                calls.append(("upsert", len(points), timeout))
                return FakeResponse({})

            def count_points(self, collection, exact, timeout):
                calls.append(("count", collection, exact, timeout))
                return FakeResponse({"result": {"count": 42}})

            def delete_by_filter(self, filter_body, timeout):
                calls.append(("delete_by_filter", timeout))
                return FakeResponse({})

            def scroll(self, collection, body, timeout):
                calls.append(("scroll", collection, timeout))
                return FakeResponse({"result": {"points": [], "next_page_offset": None}})

            def ensure_collection(self, **kwargs):
                calls.append(("ensure_collection", kwargs))
                return FakeResponse({})

        fake_gateway = FakeGateway()

        with patch("vectorstore.qdrant_http.QdrantHTTPGateway", return_value=fake_gateway):
            client = QdrantHTTP(
                settings=QdrantSettings(
                    scheme="http",
                    host="qdrant-host",
                    port=6333,
                    collection="toy_collection",
                    api_key=None,
                    timeout=1.0,
                    max_attempts=1,
                ),
                http_settings=QdrantHTTPSettings(
                    log_latency=False,
                    search_all=False,
                    search_all_per_collection=0,
                    fallback_all=False,
                    fallback_per_collection=0,
                    upsert_timeout=2.0,
                    search_timeout=2.0,
                    count_timeout=2.0,
                    delete_timeout=2.0,
                    text_timeout=2.0,
                    text_fallback_terms=3,
                    text_scroll_hard_cap=500,
                    text_scroll_batch=32,
                ),
            )

            self.assertEqual(client.search([0.1, 0.2], top_k=2), [{"id": "1", "score": 0.4}])
            client.upsert([{"id": "a", "vector": [1, 2], "payload": {}}])
            self.assertEqual(client.count_points(), 42)
            client.delete_by_filter({"must": []})

        self.assertIn(("search", "toy_collection", 2.0), calls)
        self.assertIn(("upsert", 1, 2.0), calls)
        self.assertIn(("count", "toy_collection", True, 2.0), calls)
        self.assertIn(("delete_by_filter", 2.0), calls)

    def test_qdrant_http_defaults_search_all_limit_to_top_k_when_not_configured(self) -> None:
        from vectorstore.qdrant_http import QdrantHTTP
        from vectorstore.settings import QdrantHTTPSettings, QdrantSettings

        limits: list[int] = []

        class FakeSession:
            def request(self, *args: object, **kwargs: object):
                raise AssertionError("network must not be used in this test")

        client = QdrantHTTP(
            settings=QdrantSettings(
                scheme="http",
                host="qdrant-host",
                port=6333,
                collection="toy_collection",
                api_key=None,
                timeout=2.0,
                max_attempts=1,
            ),
            http_settings=QdrantHTTPSettings(
                log_latency=False,
                search_all=True,
                search_all_per_collection=0,
                fallback_all=False,
                fallback_per_collection=0,
                upsert_timeout=2.0,
                search_timeout=2.0,
                count_timeout=2.0,
                delete_timeout=2.0,
                text_timeout=2.0,
                text_fallback_terms=1,
                text_scroll_hard_cap=100,
                text_scroll_batch=16,
            ),
        )

        def fake_search_collection(collection: str, body: dict[str, object], timeout: float) -> list[dict[str, object]]:
            limits.append(int(body["limit"]))
            return []

        with patch("vectorstore.qdrant_http.requests.Session", return_value=FakeSession()), patch.object(
            client,
            "list_collections",
            return_value=["a", "b"],
        ), patch.object(
            client,
            "_search_collection",
            side_effect=fake_search_collection,
        ):
            client.search([0.1], top_k=7, with_vector=False, with_payload=True)

        self.assertEqual(limits, [7, 7])

    def test_qdrant_collection_helpers_parse_names_counts_and_vector_size(self) -> None:
        from vectorstore.collections import (
            collection_names,
            pick_most_populated_collection,
            vector_size_from_config,
        )

        self.assertEqual(
            collection_names({"result": {"collections": [{"name": "a"}, {"name": "b"}, {}]}}),
            ["a", "b"],
        )
        self.assertEqual(
            pick_most_populated_collection([("empty", 0), ("missing", None), ("full", 12)]),
            "full",
        )
        self.assertEqual(
            vector_size_from_config({"config": {"params": {"vectors": {"size": 384}}}}),
            384,
        )
        self.assertEqual(
            vector_size_from_config({"config": {"params": {"vectors": {"params": {"text": {"size": 768}}}}}}),
            768,
        )

    def test_qdrant_response_parsers_are_fault_tolerant(self) -> None:
        from vectorstore.qdrant_responses import (
            parse_collection_config,
            parse_collection_names,
            parse_count,
            parse_scroll_points,
            parse_search_result,
        )

        self.assertEqual(parse_collection_names({"result": {"collections": [{"name": "a"}, {}]}}), ["a"])
        self.assertEqual(parse_search_result({"result": [{"id": "x", "score": 0.9}]}), [{"id": "x", "score": 0.9}])
        self.assertEqual(parse_search_result({"other": []}), [])
        self.assertEqual(parse_count({"result": {"count": "9"}}), 9)
        self.assertIsNone(parse_count({"result": {"count": None}}))
        self.assertIsNone(parse_count({"result": {}}))
        points, next_offset = parse_scroll_points({"result": {"points": [{"id": "a"}], "next_page_offset": "abc"}})
        self.assertEqual(points, [{"id": "a"}])
        self.assertEqual(next_offset, "abc")
        self.assertEqual(parse_collection_config({"result": {"config": 1}}), {"config": 1})

    def test_qdrant_interpretation_helpers_handle_404_and_missing_scores(self) -> None:
        from vectorstore.qdrant_interpretation import (
            attach_collection,
            parse_scroll_payload,
            parse_search_payload,
            sort_hits_by_score,
        )
        from requests import HTTPError

        class FakeResponse:
            def __init__(self, status_code: int, payload: dict[str, object] | None = None) -> None:
                self.status_code = status_code
                self._payload = payload or {}

            def json(self) -> dict[str, object]:
                return self._payload

            def raise_for_status(self) -> None:
                if self.status_code >= 400:
                    raise HTTPError(f"status={self.status_code}")

        no_rows = FakeResponse(404, {"result": []})
        self.assertEqual(parse_search_payload(no_rows, empty_on_not_found=True), [])
        self.assertEqual(parse_scroll_payload(no_rows, empty_on_not_found=True), ([], None))

        with self.assertRaises(HTTPError):
            parse_search_payload(no_rows, empty_on_not_found=False)

        hits = [
            {"id": "1", "score": 0.12},
            {"id": "2", "score": 0.87},
            {"id": "3"},
            {"id": "4", "score": 0.5},
        ]
        self.assertEqual(sort_hits_by_score(hits)[:2], [{"id": "2", "score": 0.87}, {"id": "4", "score": 0.5}])
        self.assertEqual(
            sort_hits_by_score(hits, limit=2),
            [{"id": "2", "score": 0.87}, {"id": "4", "score": 0.5}],
        )

        with_collection = attach_collection([{"id": "x"}, {"id": "y", "collection": "custom"}], "default")
        self.assertEqual(with_collection[0]["collection"], "default")
        self.assertEqual(with_collection[1]["collection"], "custom")

        missing_payload = FakeResponse(200, {})
        points, next_offset = parse_scroll_payload(missing_payload, empty_on_not_found=True)
        self.assertEqual(points, [])
        self.assertIsNone(next_offset)

    def test_qdrant_search_helpers_normalize_and_merge_results(self) -> None:
        from vectorstore.qdrant_search import (
            merge_search_results,
            normalize_query_inputs,
            search_with_fallback_collections,
        )

        self.assertEqual(normalize_query_inputs(["", "toy", None, "graph"], ["", "title"]), (["toy", "graph"], ["title"]))

        merged = merge_search_results(
            [
                ("a", [{"id": "1", "score": 0.2}, {"id": "2", "score": 0.7}]),
                ("b", [{"id": "3", "score": 0.9}]),
            ],
            top_k=2,
        )
        self.assertEqual(
            merged,
            [
                {"id": "3", "score": 0.9, "collection": "b"},
                {"id": "2", "score": 0.7, "collection": "a"},
            ],
        )

        calls: list[tuple[str, dict, float]] = []

        def execute(collection: str, body: dict, timeout: float):
            calls.append((collection, dict(body), timeout))
            return [{"id": f"{collection}-1", "score": 0.1}]

        merged_all = search_with_fallback_collections(
            ["alpha", "beta"],
            {"vector": [0.1], "limit": 9},
            timeout=8.0,
            top_k=2,
            per_collection_limit=3,
            execute=execute,
        )
        self.assertEqual(len(calls), 2)
        self.assertEqual(calls[0][0], "alpha")
        self.assertEqual(calls[0][1]["limit"], 3)
        self.assertEqual(merged_all[0]["id"], "alpha-1")
        self.assertEqual(merged_all[0]["collection"], "alpha")


if __name__ == "__main__":
    unittest.main()
