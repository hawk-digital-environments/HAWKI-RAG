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

ROOT = Path(__file__).resolve().parents[3]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

TESTS_ROOT = ROOT / "tests"
if str(TESTS_ROOT) not in sys.path:
    sys.path.insert(0, str(TESTS_ROOT))

from characterization_support import (
    install_optional_dependency_stubs,
)

install_optional_dependency_stubs()


class GraphFallbackCharacterizationTests(unittest.TestCase):
    """Protect graph extraction, filtering, cleanup, and visualization behavior."""

    def test_raganything_edge_parser_prefers_recent_edges_for_current_file(
        self,
    ) -> None:
        from hawki_indexer_worker.adapters.raganything.edge_parser import (
            triplets_from_raganything_edges,
        )

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

    def test_graph_cache_clear_removes_doc_status_and_lightrag_cache_files(
        self,
    ) -> None:
        from hawki_indexer_worker.adapters.raganything.cache_files import (
            clear_graph_cache_files,
        )

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

    def test_raganything_llm_cache_fallback_recovers_delimited_and_table_relations(
        self,
    ) -> None:
        from hawki_indexer_worker.adapters.raganything.fallback_parser import (
            parse_raganything_llm_cache,
        )

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

            self.assertEqual(
                parse_raganything_llm_cache(cache_path),
                [
                    ("HAWKI", "is defined by", "Retrieval-Augmented Generation"),
                    ("HAWKI", "uses vector search", "Qdrant"),
                    ("HAWKI", "persists relations", "Neo4j"),
                ],
            )

    def test_raganything_llm_cache_fallback_can_scope_to_current_extraction(
        self,
    ) -> None:
        from hawki_indexer_worker.adapters.raganything.fallback_parser import (
            parse_raganything_llm_cache,
        )

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

    def test_source_filter_drops_prompt_examples_and_keeps_grounded_triplets(
        self,
    ) -> None:
        from hawki_rag_stores.neo4j.traversal import filter_triplets_to_source

        source = (
            "HAWKI uses Qdrant for vector search and Neo4j for graph relationships."
        )
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
        from hawki_rag_stores.neo4j.traversal import clean_triplets

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

    def test_graph_triplet_filter_drops_runtime_metadata_and_malformed_relations(
        self,
    ) -> None:
        from hawki_rag_stores.neo4j.traversal import filter_triplets_to_source

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
            (
                "Universität: Universität zu Lübeck",
                "has_url<|#|",
                "https://uni-luebeck.de/",
            ),
            ("uni-luebeck", "is_named_as", "universität zu lübeck"),
            ("00001.md", "generated,", "ingest_5471720dd2ff9589e685cbc5"),
            (
                "uni-luebeck",
                "is_referenced_by,",
                "91e3087790ed9ea472789512573135d3df84d68032b2c2d6698e8499f732cf64",
            ),
            ("Universität zu Lübeck", "offers", "Biomedical Engineering"),
        ]

        self.assertEqual(
            filter_triplets_to_source(triplets, source),
            [("Universität zu Lübeck", "offers", "Biomedical Engineering")],
        )

    def test_converter_markdown_cleaner_strips_leading_metadata_rows_only(self) -> None:
        from hawki_rag_text.markdown import strip_leading_converter_markdown_noise

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

    def test_prepare_documents_strips_converter_markdown_noise_before_chunking(
        self,
    ) -> None:
        from hawki_indexer_worker.indexing.chunking import prepare_documents

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
                payload={
                    "title": "Policy",
                    "source_url": "upload://policy.md",
                    "source_format": "markdown",
                },
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
        self.assertTrue(
            chunk_records[0]["content"].startswith("# Techniker Krankenkasse")
        )
        self.assertNotIn("Chunk Number", chunk_records[0]["content"])

    def test_graph_text_cleaner_normalizes_with_env_limits(self) -> None:
        from hawki_indexer_worker.adapters.raganything.text import clean_graph_text

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
        from hawki_rag_stores.neo4j.text import graph_from_text

        class FakeService:
            def extract_triplets(
                self, text: str, engine: str
            ) -> list[tuple[str, str, str]]:
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

    def test_graph_visualization_write_can_be_disabled_with_injected_settings(
        self,
    ) -> None:
        from hawki_rag_stores.neo4j.visualization import write_graph_visualization
        from hawki_rag_stores.neo4j.visualization_settings import (
            GraphVisualizationSettings,
        )

        settings = GraphVisualizationSettings(
            enabled=False,
            uri="bolt://neo4j:7687",
            user="neo4j",
            password="password",
            database=None,
            limit=10,
        )

        with (
            tempfile.TemporaryDirectory() as tmp,
            patch(
                "hawki_rag_stores.neo4j.visualization.Neo4jGraphVisualization"
            ) as mocked_vis,
        ):
            result = write_graph_visualization(Path(tmp), settings=settings)
            self.assertIsNone(result)
            mocked_vis.assert_not_called()

    def test_graph_visualization_writer_uses_injected_settings(self) -> None:
        from hawki_rag_stores.neo4j.visualization import write_graph_visualization
        from hawki_rag_stores.neo4j.visualization_settings import (
            GraphVisualizationSettings,
        )
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

        with (
            tempfile.TemporaryDirectory() as tmp,
            patch(
                "hawki_rag_stores.neo4j.visualization.Neo4jGraphVisualization",
                return_value=fake_visualizer,
            ) as mocked_vis,
        ):
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

            written = json.loads(
                (Path(tmp) / "neo4j_graph_visualization.json").read_text(
                    encoding="utf-8"
                )
            )
            self.assertEqual(written["ok"], True)
            self.assertEqual(written["nodes"], snapshot_payload["nodes"])
