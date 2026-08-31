"""Query scenarios from lexical fallback and rewrite through scoped ranking and context assembly."""

from __future__ import annotations

import unittest
from unittest.mock import patch


class QueryCharacterizationTests(unittest.TestCase):
    """Protect query settings, fallback, deduplication, reranking, rewriting, and execution flow."""

    def test_query_hit_helpers_preserve_distinct_chunks_until_ranking(self) -> None:
        from hawki_bridge.application.query import hits as query_hits

        primary = [
            {
                "id": "a",
                "score": 0.2,
                "payload": {"doc_id": "doc-a", "title": "Toy Train"},
            },
            {
                "id": "b",
                "score": 0.4,
                "payload": {"doc_id": "doc-b", "title": "Blocks"},
            },
        ]
        secondary = [
            {
                "id": "a2",
                "score": 0.9,
                "payload": {"doc_id": "doc-a", "title": "Toy Train Exact Match"},
            },
            {
                "id": "c",
                "score": 0.8,
                "payload": {"doc_id": "doc-c", "title": "Blocks"},
            },
        ]

        merged = query_hits.merge_hits(primary, secondary, limit=3)
        deduped = query_hits.dedupe_hits_by_identity(merged)

        self.assertEqual(len(merged), 3)
        self.assertEqual([hit["id"] for hit in deduped], [hit["id"] for hit in merged])

    def test_query_hit_merge_normalizes_stage_scores_for_the_same_point(self) -> None:
        from hawki_bridge.application.query import hits as query_hits

        merged = query_hits.merge_hits(
            [
                {
                    "id": "answer",
                    "score": 0.246335,
                    "payload": {"doc_id": "fees", "chunk_index": 1},
                }
            ],
            [
                {
                    "id": "answer",
                    "score": 0.580014,
                    "payload": {"doc_id": "fees", "chunk_index": 1},
                },
                {
                    "id": "introduction",
                    "score": 0.523358,
                    "payload": {"doc_id": "fees", "chunk_index": 0},
                },
                {
                    "id": "trailing",
                    "score": 0.486133,
                    "payload": {"doc_id": "fees", "chunk_index": 2},
                },
            ],
            limit=3,
        )

        self.assertEqual(
            [hit["id"] for hit in merged], ["answer", "introduction", "trailing"]
        )
        self.assertEqual(merged[0]["score"], 1.0)
        self.assertGreater(merged[0]["score"], merged[1]["score"])

    def test_query_hit_dedupe_keeps_chunks_with_the_same_document_metadata(
        self,
    ) -> None:
        from hawki_bridge.application.query import hits as query_hits

        hits = [
            {
                "id": "chunk-1",
                "score": 0.9,
                "payload": {
                    "doc_id": "fees",
                    "chunk_index": 1,
                    "title": "Gebührenordnung",
                    "source_url": "upload://gebuehrenordnung.pdf",
                },
            },
            {
                "id": "chunk-2",
                "score": 0.8,
                "payload": {
                    "doc_id": "fees",
                    "chunk_index": 2,
                    "title": "Gebührenordnung",
                    "source_url": "upload://gebuehrenordnung.pdf",
                },
            },
            {
                "id": "chunk-1",
                "score": 0.7,
                "payload": {
                    "doc_id": "fees",
                    "chunk_index": 1,
                    "title": "Gebührenordnung",
                    "source_url": "upload://gebuehrenordnung.pdf",
                },
            },
        ]

        deduped = query_hits.dedupe_hits_by_identity(hits)

        self.assertEqual([hit["id"] for hit in deduped], ["chunk-1", "chunk-2"])

    def test_legacy_dedupe_helper_delegates_to_identity_deduplication(self) -> None:
        from hawki_bridge.application.query import hits as query_hits

        hits = [{"id": "chunk-1", "payload": {"title": "Shared title"}}]
        delegated_result = [{"id": "delegated"}]

        with patch.object(
            query_hits,
            "dedupe_hits_by_identity",
            return_value=delegated_result,
        ) as dedupe:
            result = query_hits.dedupe_hits_by_title_or_url(hits)

        dedupe.assert_called_once_with(hits)
        self.assertIs(result, delegated_result)

    def test_query_hit_merge_uses_chunk_index_when_point_id_is_missing(self) -> None:
        from hawki_bridge.application.query import hits as query_hits

        merged = query_hits.merge_hits(
            [{"score": 0.4, "payload": {"doc_id": "doc-a", "chunk_index": 0}}],
            [{"score": 0.8, "payload": {"doc_id": "doc-a", "chunk_index": 1}}],
            limit=2,
        )

        self.assertEqual(
            [hit["payload"]["chunk_index"] for hit in merged],
            [1, 0],
        )

    def test_graph_fusion_does_not_collapse_chunks_from_the_same_document(self) -> None:
        from hawki_bridge.application.query import hits as query_hits

        fused = query_hits.fuse_hits(
            [
                {"id": "chunk-1", "score": 0.8, "payload": {"doc_id": "doc-a"}},
                {"id": "chunk-2", "score": 0.6, "payload": {"doc_id": "doc-a"}},
            ],
            [
                {"id": "relation-1", "score": 0.5, "payload": {"doc_id": "doc-a"}},
                {"id": "relation-2", "score": 0.25, "payload": {"doc_id": "doc-a"}},
            ],
            sem_weight=1.0,
            str_weight=0.4,
        )

        self.assertEqual([hit["id"] for hit in fused], ["chunk-1", "chunk-2"])
        self.assertEqual([hit["score"] for hit in fused], [1.1, 0.9])

    def test_graph_fusion_keeps_one_aggregate_for_a_structural_only_document(
        self,
    ) -> None:
        from hawki_bridge.application.query import hits as query_hits

        fused = query_hits.fuse_hits(
            [],
            [
                {"id": "relation-1", "score": 0.5, "payload": {"doc_id": "doc-a"}},
                {"id": "relation-2", "score": 0.25, "payload": {"doc_id": "doc-a"}},
            ],
            sem_weight=1.0,
            str_weight=0.4,
        )

        self.assertEqual([hit["id"] for hit in fused], ["relation-1"])
        self.assertAlmostEqual(fused[0]["score"], 0.3)

    def test_query_context_summaries_trim_to_token_budget(self) -> None:
        from hawki_bridge.application.query import context as query_context

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

        summaries, trimmed, used_tokens = query_context.prepare_context_summaries(
            hits,
            max_docs=1,
            max_tokens=20,
        )

        self.assertEqual(len(summaries), 1)
        self.assertEqual(trimmed, [1])
        self.assertLessEqual(used_tokens, 30)
        self.assertEqual(summaries[0]["title"], "Toy Catalog")
