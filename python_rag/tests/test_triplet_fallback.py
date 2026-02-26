import os
import sys
import tempfile
import unittest
from unittest import mock


ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if ROOT not in sys.path:
    sys.path.insert(0, ROOT)


class RAGAnythingGraphExtractionTests(unittest.TestCase):
    def test_graph_embed_junk_filter_allowlist_and_denylist(self):
        from core.rag_service import _graph_embed_junk_reason

        with mock.patch.dict(os.environ, {"GRAPH_EMBED_JUNK_STRICT": "true"}, clear=False):
            self.assertEqual(_graph_embed_junk_reason("Stage"), "strict_boilerplate_label")

        with mock.patch.dict(
            os.environ,
            {
                "GRAPH_EMBED_JUNK_STRICT": "true",
                "GRAPH_EMBED_JUNK_ALLOWLIST": "exact:stage",
            },
            clear=False,
        ):
            self.assertIsNone(_graph_embed_junk_reason("Stage"))

        with mock.patch.dict(
            os.environ,
            {
                "GRAPH_EMBED_JUNK_STRICT": "false",
                "GRAPH_EMBED_JUNK_DENYLIST": "contains:main content",
            },
            clear=False,
        ):
            self.assertEqual(_graph_embed_junk_reason("Skip to Main Content"), "env_denylist")

    def test_extract_triplets_uses_official_raganything_path(self):
        from core.rag_service import RAGService

        with tempfile.TemporaryDirectory() as tmpdir, mock.patch.dict(os.environ, {"RAG_WORKING_DIR": tmpdir}):
            service = RAGService()
            expected = [("Alice", "employment", "HAWK")]

            with mock.patch.object(
                service,
                "_extract_triplets_raganything",
                return_value=expected,
            ) as patched:
                trips = service.extract_triplets(
                    "Alice works at HAWK University.",
                    "raganything",
                    provider=mock.Mock(),
                )

            self.assertEqual(trips, expected)
            patched.assert_called_once()

    def test_triplet_export_filters_raganything_edges(self):
        from core.rag_service import RAGService

        with tempfile.TemporaryDirectory() as tmpdir, mock.patch.dict(os.environ, {"RAG_WORKING_DIR": tmpdir}):
            service = RAGService()
            edges = [
                {
                    "source": "Alice",
                    "target": "HAWK",
                    "keywords": "employment, university",
                    "description": "Alice works at HAWK",
                    "file_path": "/doc/a.md",
                    "created_at": 200,
                },
                {
                    # same file, older edge should be ignored when recent edges exist
                    "source": "Alice",
                    "target": "OldOrg",
                    "keywords": "old_relation",
                    "file_path": "/doc/a.md",
                    "created_at": 10,
                },
                {
                    # different file should be ignored
                    "source": "Alice",
                    "target": "Other",
                    "keywords": "other",
                    "file_path": "/doc/b.md",
                    "created_at": 300,
                },
            ]

            trips = service._triplets_from_raganything_edges(
                edges=edges,
                file_ref="/doc/a.md",
                created_at_floor=150,
            )
            self.assertEqual(trips, [("Alice", "employment", "HAWK")])


if __name__ == "__main__":
    unittest.main()
