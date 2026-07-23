"""Graph characterization scenarios from extraction through dataset-scoped Neo4j persistence."""

from __future__ import annotations

import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

TESTS_ROOT = ROOT / "tests"
if str(TESTS_ROOT) not in sys.path:
    sys.path.insert(0, str(TESTS_ROOT))

from characterization_support import (
    install_optional_dependency_stubs,
    neo4j_exceptions_module as _neo4j_exceptions_module,
)

install_optional_dependency_stubs()



class GraphFallbackCharacterizationTests(unittest.TestCase):
    """Protect graph extraction, filtering, cleanup, and visualization behavior."""
    def test_raganything_edge_parser_prefers_recent_edges_for_current_file(self) -> None:
        from infrastructure.raganything.edge_parser import triplets_from_raganything_edges

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
        from infrastructure.raganything.cache import clear_graph_cache_files

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
        from application.service import RAGService

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

    def test_raganything_llm_cache_fallback_can_scope_to_current_extraction(self) -> None:
        from infrastructure.raganything.fallback_parser import parse_raganything_llm_cache

        with tempfile.TemporaryDirectory() as tmp:
            cache_path = Path(tmp) / "kv_store_llm_response_cache.json"
            cache_path.write_text(
                json.dumps(
                    {
                        "stale": {
                            "return": "relation<|#|>solution04-final-gngrqk4l.py<|#|>graph<|#|>related_to,",
                            "chunk_id": "doc_old:extract:1-chunk-000",
                            "create_time": 100,
                        },
                        "current": {
                            "return": "relation<|#|>skill1-o1fxaa1u.md<|#|>RAG-Anything<|#|>attached_for_parsing",
                            "chunk_id": "doc_current:extract:2-chunk-000",
                            "create_time": 200,
                        },
                    }
                ),
                encoding="utf-8",
            )

            self.assertEqual(
                parse_raganything_llm_cache(
                    cache_path,
                    chunk_id_prefix="doc_current:extract:2",
                    created_at_floor=150,
                ),
                [("skill1-o1fxaa1u.md", "attached_for_parsing", "RAG-Anything")],
            )

    def test_source_filter_drops_prompt_examples_and_keeps_grounded_triplets(self) -> None:
        from infrastructure.graph.graph_utils import filter_triplets_to_source

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

    def test_graph_cleanup_returns_bidirectional_duplicates_once(self) -> None:
        from infrastructure.graph.graph_utils import clean_triplets

        self.assertEqual(
            clean_triplets(
                [
                    ("Gebührenordnung", "covered_by", "Portokosten"),
                    ("Portokosten", "covered_by,", "Gebührenordnung"),
                    ("HAWK", "equivalent_to,", "University"),
                    ("University", "equivalent", "HAWK"),
                    ("HAWK", "synonym", "University"),
                ]
            ),
            [
                ("Gebührenordnung", "covered_by", "Portokosten"),
                ("HAWK", "equivalent", "University"),
                ("HAWK", "synonym", "University"),
            ],
        )

    def test_graph_triplet_filter_drops_runtime_metadata_and_malformed_relations(self) -> None:
        from infrastructure.graph.graph_utils import filter_triplets_to_source

        source = (
            "chunk file nextChunk next file Chunk Number File Name\n"
            "Die Universität zu Lübeck bietet Studiengänge in Medizin, Informatik "
            "und Biomedical Engineering an."
        )
        triplets = [
            ("chunk", "Chunk Number,File Name", "file"),
            ("chunk", "Chunk Number", "file"),
            ("file", "Chunk Number", "nextChunk"),
            ("nextChunk", "Chunk Number,File Name", "file"),
            ("next file", "Chunk Number", "file"),
            ("und", "mentions", "Biomedical Engineering"),
            ("Die", "Title, Section", "Techniker"),
            ("Techniker", "is", "Die"),
            ("Universität zu Lübeck", "ist", "Biomedical Engineering"),
            ("https://uni-luebeck.de/", "has URL", "universität zu lübeck"),
            ("ingest_ee55bd5d94f149b51f543d46", "is related to", "uni-luebeck"),
            ("Universität: Universität zu Lübeck", "has_url<|#|", "https://uni-luebeck.de/"),
            ("uni-luebeck", "is_named_as", "universität zu lübeck"),
            ("00001.md", "generated,", "ingest_5471720dd2ff9589e685cbc5"),
            ("uni-luebeck", "is_referenced_by,", "91e3087790ed9ea472789512573135d3df84d68032b2c2d6698e8499f732cf64"),
            ("Universität zu Lübeck", "offers", "Biomedical Engineering"),
        ]

        self.assertEqual(
            filter_triplets_to_source(triplets, source),
            [("Universität zu Lübeck", "offers", "Biomedical Engineering")],
        )

    def test_converter_markdown_cleaner_strips_leading_metadata_rows_only(self) -> None:
        from common.converter_markdown import strip_leading_converter_markdown_noise

        text = "\n".join(
            [
                "",
                "| chunk | Chunk Number,File Name | file |",
                "| --- | --- | --- |",
                "| file | Chunk Number | nextChunk |",
                "",
                "# Techniker Krankenkasse",
                "Versicherungsschutz fuer Herrn Yazdan Asadi.",
                "",
                "| File Name | Beschreibung |",
                "| Antrag.pdf | Normale Dokumenttabelle |",
            ]
        )

        cleaned = strip_leading_converter_markdown_noise(text)

        self.assertTrue(cleaned.startswith("# Techniker Krankenkasse"))
        self.assertNotIn("nextChunk", cleaned)
        self.assertIn("| File Name | Beschreibung |", cleaned)

    def test_prepare_documents_strips_converter_markdown_noise_before_chunking(self) -> None:
        from application.workflows.ingest.chunking import prepare_documents

        docs = [
            SimpleNamespace(
                id="doc-md",
                text="\n".join(
                    [
                        "| chunk | Chunk Number,File Name | file |",
                        "| --- | --- | --- |",
                        "| file | Chunk Number | nextChunk |",
                        "",
                        "# Techniker Krankenkasse",
                        "Versicherungsschutz fuer Herrn Yazdan Asadi.",
                    ]
                ),
                payload={"title": "Policy", "source_url": "upload://policy.md", "source_format": "markdown"},
            )
        ]

        chunk_records, stats = prepare_documents(
            docs,
            chunk_chars=1000,
            chunk_overlap=0,
            default_job_id="job-md",
        )

        self.assertEqual(stats["processed_docs"], 1)
        self.assertEqual(len(chunk_records), 1)
        self.assertTrue(chunk_records[0]["content"].startswith("# Techniker Krankenkasse"))
        self.assertNotIn("Chunk Number", chunk_records[0]["content"])

    def test_graph_text_cleaner_normalizes_with_env_limits(self) -> None:
        from infrastructure.raganything.text import clean_graph_text

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
        from infrastructure.graph.graph_text import graph_from_text

        class FakeService:
            def extract_triplets(self, text: str, engine: str) -> list[tuple[str, str, str]]:
                return [
                    ("HAWKI", "uses", "Qdrant"),
                    ("Qdrant", "supports", "vector search"),
                ]

        result = graph_from_text(
            SimpleNamespace(text="sample", engine="engine-a"),
            rag_service=FakeService(),
            graph_perf_log=True,
        )

        self.assertEqual(result["ok"], True)
        self.assertEqual(result["triplets"], 2)
        self.assertIs(result["persisted"], False)

    def test_graph_visualization_write_can_be_disabled_with_injected_settings(self) -> None:
        from infrastructure.graph.graph_visualization import write_graph_visualization
        from infrastructure.graph.visualization_settings import GraphVisualizationSettings

        settings = GraphVisualizationSettings(
            enabled=False,
            uri="bolt://neo4j:7687",
            user="neo4j",
            password="password",
            database=None,
            limit=10,
        )

        with tempfile.TemporaryDirectory() as tmp, patch("infrastructure.graph.graph_visualization.Neo4jGraphVisualization") as mocked_vis:
            result = write_graph_visualization(Path(tmp), settings=settings)
            self.assertIsNone(result)
            mocked_vis.assert_not_called()

    def test_graph_visualization_writer_uses_injected_settings(self) -> None:
        from infrastructure.graph.graph_visualization import write_graph_visualization
        from infrastructure.graph.visualization_settings import GraphVisualizationSettings
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
            "infrastructure.graph.graph_visualization.Neo4jGraphVisualization",
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
    """Explain how graph runtime settings are parsed and reported."""
    def test_raganything_graph_settings_parse_and_injected_runtime_summary(self) -> None:
        from infrastructure.raganything.raganything_client import RagAnythingGraphService
        from infrastructure.raganything.raganything_settings import load_raganything_graph_settings

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
    """Protect graph normalization, deduplication, and junk filtering rules."""
    def test_graph_utils_normalization_and_dedupe(self) -> None:
        from infrastructure.raganything.raganything_utils import dedupe_triplets, normalize_graph_embed_text

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
        from infrastructure.raganything.raganything_utils import graph_embed_junk_reason, is_junk_graph_label

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
    """Protect graph cache identity, extraction IDs, and scoped cleanup."""
    def test_graph_cache_key_changes_with_db_name(self) -> None:
        from infrastructure.raganything.raganything_client_config import graph_runtime_cache_key
        from infrastructure.raganything.raganything_settings import load_raganything_graph_settings

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
        from infrastructure.raganything.raganything_extract import (
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
        from infrastructure.raganything.raganything_cache import scrub_raganything_kv_graph_junk
        from infrastructure.raganything.raganything_utils import is_junk_graph_label

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
    """Describe the stable graph runtime summary exposed to operators."""
    def test_graph_runtime_summary_builder_returns_expected_shape(self) -> None:
        from infrastructure.raganything.raganything_summary import build_graph_runtime_summary
        from infrastructure.raganything.raganything_settings import load_raganything_graph_settings

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
    """Protect synchronous and asynchronous graph lifecycle cleanup."""
    def test_graph_loop_runs_sync_coro(self) -> None:
        from infrastructure.raganything.raganything_loop import RagAnythingGraphLoop

        async def _value() -> str:
            return "ok"

        loop = RagAnythingGraphLoop()
        self.assertEqual(loop.run_sync(_value()), "ok")

    def test_graph_loop_finalizes_raganything_storages_on_owned_loop(self) -> None:
        import asyncio

        from infrastructure.raganything.raganything_loop import RagAnythingGraphLoop

        calls: dict[str, object] = {}

        class FakeClient:
            async def finalize_storages(self) -> None:
                calls["finalize_loop"] = asyncio.get_running_loop()

            def close(self) -> None:
                calls["close_called"] = True

        graph_loop = RagAnythingGraphLoop()
        owned_loop = graph_loop.ensure_rag_graph_loop()

        graph_loop.close_raganything_instance(FakeClient())

        self.assertIs(calls["finalize_loop"], owned_loop)
        self.assertNotIn("close_called", calls)

    def test_graph_loop_retains_sync_and_async_close_fallbacks(self) -> None:
        import asyncio

        from infrastructure.raganything.raganything_loop import RagAnythingGraphLoop

        calls: list[object] = []

        class SyncCloseClient:
            def close(self) -> None:
                calls.append("sync")

        class AsyncCloseClient:
            async def close(self) -> None:
                calls.append(asyncio.get_running_loop())

        graph_loop = RagAnythingGraphLoop()
        owned_loop = graph_loop.ensure_rag_graph_loop()

        graph_loop.close_raganything_instance(SyncCloseClient())
        graph_loop.close_raganything_instance(AsyncCloseClient())

        self.assertEqual(calls, ["sync", owned_loop])


class RagAnythingRuntimeCharacterizationTests(unittest.TestCase):
    """Verify Neo4j runtime variables are prepared for graph ingestion."""
    def test_prepare_lightrag_neo4j_env_sets_runtime_variables(self) -> None:
        from infrastructure.raganything.raganything_runtime import prepare_lightrag_neo4j_env
        from infrastructure.raganything.raganything_settings import load_raganything_graph_settings

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


class Neo4jCharacterizationTests(unittest.TestCase):
    """Protect resilient parsing, dataset-scoped writes, retries, and executor injection."""
    def test_neo4j_response_parsing_is_robust(self) -> None:
        from infrastructure.graph.neo4j_responses import (
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
                    Row({"subject": "O", "relation": "R,", "object": "S"}),
                    Row({"subject": "A", "relation": "equivalent_to,", "object": "B"}),
                    Row({"subject": "B", "relation": "equivalent", "object": "A"}),
                    Row({"subject": "A", "relation": "synonym", "object": "B"}),
                    Row({"subject": "bad", "relation": "R"}),
                ]
            ),
            [
                {"subject": "S", "relation": "R", "object": "O"},
                {"subject": "A", "relation": "equivalent", "object": "B"},
                {"subject": "A", "relation": "synonym", "object": "B"},
            ],
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
        from infrastructure.graph.neo4j_graph import Neo4jGraph

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
            dataset_id="dataset-a",
            neo4j_namespace="hawki_dataset_a",
        )

        self.assertEqual(len(calls), 1)
        cypher, params = calls[0]
        self.assertIn(
            "MERGE (s:Entity {entity_key: row.s_key, dataset_id: row.dataset_id, neo4j_namespace: row.neo4j_namespace})",
            cypher,
        )
        self.assertEqual(
            params["rows"],
            [
                {
                    "s": "HAWKI",
                    "s_key": "hawki",
                    "r": "USES",
                    "o": "Qdrant",
                    "o_key": "qdrant",
                    "doc_id": "doc-1",
                    "dataset_id": "dataset-a",
                    "neo4j_namespace": "hawki_dataset_a",
                },
                {
                    "s": "HAWKI",
                    "s_key": "hawki",
                    "r": "PERSISTS",
                    "o": "Neo4j",
                    "o_key": "neo4j",
                    "doc_id": "doc-1",
                    "dataset_id": "dataset-a",
                    "neo4j_namespace": "hawki_dataset_a",
                },
            ],
        )

    def test_upsert_triplet_rows_use_case_insensitive_entity_keys(self) -> None:
        from infrastructure.graph.neo4j_requests import build_triplet_rows

        self.assertEqual(
            build_triplet_rows(
                [
                    ("Rrolf", "mentions", "RAG-System"),
                    ("rrolf", "mentions", "Rag System"),
                ],
                "doc-1",
                dataset_id="dataset-a",
                neo4j_namespace="hawki_dataset_a",
            ),
            [
                {
                    "s": "Rrolf",
                    "s_key": "rrolf",
                    "r": "mentions",
                    "o": "RAG-System",
                    "o_key": "rag system",
                    "doc_id": "doc-1",
                    "dataset_id": "dataset-a",
                    "neo4j_namespace": "hawki_dataset_a",
                },
                {
                    "s": "rrolf",
                    "s_key": "rrolf",
                    "r": "mentions",
                    "o": "Rag System",
                    "o_key": "rag system",
                    "doc_id": "doc-1",
                    "dataset_id": "dataset-a",
                    "neo4j_namespace": "hawki_dataset_a",
                },
            ],
        )

    def test_neo4j_query_executor_retries_transient_errors(self) -> None:
        from infrastructure.graph.neo4j_requests import Neo4jQueryRequest
        from infrastructure.graph.neo4j_transport import Neo4jQueryExecutor

        neo4j_exceptions = _neo4j_exceptions_module()
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
        from infrastructure.graph.neo4j_graph import Neo4jGraph
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
            dataset_id="dataset-a",
            neo4j_namespace="graph-a",
            settings=SimpleNamespace(database=None, retry_attempts=1, log_latency=False, perf_log=False),
            query_executor=executor,  # type: ignore[arg-type]
        )
        fetch_result = graph.fetch_related(
            ["toy"],
            dataset_id="dataset-a",
            neo4j_namespace="graph-a",
        )
        graph.upsert_triplets([("A", "R", "B")], doc_id="doc-1")

        self.assertEqual(fetch_result, [{"subject": "A", "relation": "R", "object": "B"}])
        self.assertEqual(executor.read_calls, 1)
        self.assertEqual(executor.write_calls, 1)
        self.assertTrue(any("UNWIND $rows" in statement for statement in executor.statements))
