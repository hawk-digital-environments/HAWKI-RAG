"""Graph characterization scenarios from extraction through dataset-scoped Neo4j persistence."""

from __future__ import annotations

import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[3]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

TESTS_ROOT = ROOT / "tests"
if str(TESTS_ROOT) not in sys.path:
    sys.path.insert(0, str(TESTS_ROOT))


class RagAnythingGraphSettingsCharacterizationTests(unittest.TestCase):
    """Explain how graph runtime settings are parsed and reported."""

    def test_raganything_graph_settings_parse_and_injected_runtime_summary(
        self,
    ) -> None:
        from hawki_indexer_worker.adapters.raganything.client import (
            RagAnythingGraphService,
        )
        from hawki_indexer_worker.adapters.raganything.settings import (
            load_raganything_graph_settings,
        )

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
        # No provider wired yet: model names stay empty until a request provides them.
        self.assertEqual(summary["models"]["graph_model"], "")
        self.assertEqual(summary["models"]["embed_model"], "")
        self.assertEqual(summary["limits"]["graph_doc_max_chars"], 2500)
        self.assertFalse(summary["resilience"]["graph_embed_junk_strict"])


class RagAnythingUtilsCharacterizationTests(unittest.TestCase):
    """Protect graph normalization, deduplication, and junk filtering rules."""

    def test_graph_utils_normalization_and_dedupe(self) -> None:
        from hawki_indexer_worker.adapters.raganything.utilities import (
            dedupe_triplets,
            normalize_graph_embed_text,
        )

        self.assertEqual(
            normalize_graph_embed_text("  hello\n\tworld  "), "hello world"
        )

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
        from hawki_indexer_worker.adapters.raganything.utilities import (
            graph_embed_junk_reason,
            is_junk_graph_label,
        )

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
        self.assertEqual(
            graph_embed_junk_reason("Skip to main content"), "strict_boilerplate_label"
        )


class RagAnythingClientModuleCharacterizationTests(unittest.TestCase):
    """Protect graph cache identity, extraction IDs, and scoped cleanup."""

    def test_temp_graph_cleanup_handles_only_neo4j_operation_errors(self) -> None:
        from neo4j.exceptions import ServiceUnavailable

        from hawki_indexer_worker.adapters.raganything.runtime import (
            clear_lightrag_temp_graph,
        )

        class Session:
            def __init__(self, failure: BaseException) -> None:
                self.failure = failure

            def __enter__(self) -> "Session":
                return self

            def __exit__(self, *_args: object) -> None:
                return None

            def execute_write(self, _callback) -> None:
                raise self.failure

        class Driver:
            def __init__(self, failure: BaseException) -> None:
                self.failure = failure
                self.closed = False

            def session(self, **_kwargs: object) -> Session:
                return Session(self.failure)

            def close(self) -> None:
                self.closed = True

        driver = Driver(ServiceUnavailable("not ready"))
        with patch(
            "hawki_indexer_worker.adapters.neo4j_cleanup.GraphDatabase.driver",
            return_value=driver,
        ):
            clear_lightrag_temp_graph()
        self.assertTrue(driver.closed)

        programming_failure_driver = Driver(ValueError("callback bug"))
        with patch(
            "hawki_indexer_worker.adapters.neo4j_cleanup.GraphDatabase.driver",
            return_value=programming_failure_driver,
        ):
            with self.assertRaises(ValueError):
                clear_lightrag_temp_graph()
        self.assertTrue(programming_failure_driver.closed)

    def test_graph_cache_key_changes_with_db_name(self) -> None:
        from hawki_indexer_worker.adapters.raganything.client_config import (
            graph_runtime_cache_key,
        )
        from hawki_indexer_worker.adapters.raganything.settings import (
            load_raganything_graph_settings,
        )

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
        from hawki_indexer_worker.adapters.raganything.extraction_runtime import (
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
        from hawki_indexer_worker.adapters.raganything.cache import (
            scrub_raganything_kv_graph_junk,
        )
        from hawki_indexer_worker.adapters.raganything.utilities import (
            is_junk_graph_label,
        )

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
                        "doc-1": {
                            "relation_pairs": [
                                ["HAWKI", "connects", "RAG"],
                                ["N/A", "links", "Tool"],
                                ["ok", "is", "good"],
                            ]
                        },
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
                is_junk_graph_label=lambda value: is_junk_graph_label(
                    value, strict=False
                ),
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
        from hawki_indexer_worker.adapters.raganything.settings import (
            load_raganything_graph_settings,
        )
        from hawki_indexer_worker.adapters.raganything.summary import (
            build_graph_runtime_summary,
        )

        with tempfile.TemporaryDirectory() as tmp:
            working_dir = Path(tmp)
            for i in range(2):
                (working_dir / f"kv_store_doc_status_chunk_{i}.json").write_text(
                    "{}", encoding="utf-8"
                )

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
                runtime_meta={
                    "doc_status_storage": "ChunkedJsonDocStatusStorage",
                    "graph_storage": "Neo4JStorage",
                },
                graph_client_initialized=True,
            )

        self.assertEqual(summary["doc_status_chunks"]["count"], 2)
        self.assertEqual(summary["doc_status_storage"], "ChunkedJsonDocStatusStorage")
        self.assertEqual(summary["graph_storage"], "Neo4JStorage")
        self.assertEqual(summary["graph_client_initialized"], True)


class RagAnythingLoopCharacterizationTests(unittest.TestCase):
    """Protect synchronous and asynchronous graph lifecycle cleanup."""

    def test_graph_loop_runs_sync_coro(self) -> None:
        from hawki_indexer_worker.adapters.raganything.graph_loop import (
            RagAnythingGraphLoop,
        )

        async def _value() -> str:
            return "ok"

        loop = RagAnythingGraphLoop()
        self.assertEqual(loop.run_sync(_value()), "ok")

    def test_graph_loop_finalizes_raganything_storages_on_owned_loop(self) -> None:
        import asyncio

        from hawki_indexer_worker.adapters.raganything.graph_loop import (
            RagAnythingGraphLoop,
        )

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

        from hawki_indexer_worker.adapters.raganything.graph_loop import (
            RagAnythingGraphLoop,
        )

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
        from hawki_indexer_worker.adapters.raganything.runtime import (
            prepare_lightrag_neo4j_env,
        )
        from hawki_indexer_worker.adapters.raganything.settings import (
            load_raganything_graph_settings,
        )

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
