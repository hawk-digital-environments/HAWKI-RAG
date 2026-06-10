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
        from app.schemas import IngestDoc, IngestRequest, QueryRequest

        ingest = IngestRequest(
            docs=[IngestDoc(id="doc-1", text="Toy catalog", payload={"title": "Toys"})],
        )
        query = QueryRequest(query="Which toys are wooden?")

        self.assertEqual(ingest.provider, os.environ.get("RAG_DEFAULT_PROVIDER", "ollama"))
        self.assertEqual(ingest.distance, os.environ.get("QDRANT_DISTANCE", "Cosine"))
        self.assertEqual(query.top_k, 5)
        self.assertEqual(query.filters, {})

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

        with self.assertRaises(HTTPException) as raised:
            build_replacement_ingest_request("doc-1", DocumentUpsertRequest(text=" "))
        self.assertEqual(raised.exception.status_code, 400)

        request = build_replacement_ingest_request(
            "doc-1",
            DocumentUpsertRequest(
                text="Toy blocks",
                payload={"title": "Toys"},
                provider="fake",
                collection="toy_docs",
                graph=True,
            ),
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
            )

        self.assertEqual(response["provider"], "fake")
        self.assertEqual(response["embedding_model"], "embed-toys")
        self.assertEqual(response["qdrant_collection"], "toy_docs")
        self.assertEqual(response["qdrant_vector_size"], 384)
        self.assertEqual(response["reranker"]["mix_weight"], 0.7)

    def test_app_logging_config_sets_app_and_graph_logger_levels(self) -> None:
        import logging

        from app.logging_config import configure_app_logging, env_flag

        app_logger = logging.getLogger("tests.logging_config")
        ingest_logger = logging.getLogger("pipeline.ingest_logic")
        rag_logger = logging.getLogger("core.rag_service")
        old_levels = (app_logger.level, ingest_logger.level, rag_logger.level)
        try:
            logger, graph_debug, graph_debug_log = configure_app_logging(
                {"LOG_LEVEL": "WARNING", "GRAPH_DEBUG": "true", "GRAPH_DEBUG_LOG": ""},
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
