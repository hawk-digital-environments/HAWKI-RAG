"""Bridge composition characterization at provider and reranker boundaries."""

from __future__ import annotations

import unittest
from unittest.mock import patch


class BridgeCompositionCharacterizationTests(unittest.TestCase):
    def test_bridge_composition_binds_provider_and_reranker_boundaries(
        self,
    ) -> None:
        from hawki_bridge import composition

        provider = {"provider": "toy-provider"}
        ranked = [{"id": "ranked"}]
        graph_search = object()
        vector_factory = object()
        with (
            patch.object(
                composition,
                "create_model_provider",
                return_value=provider,
            ) as provider_factory,
            patch.object(composition, "rerank_hits", return_value=ranked) as reranker,
            patch.object(composition, "Neo4jReader", return_value=graph_search),
            patch.object(composition, "QdrantReader", vector_factory),
        ):
            dependencies = composition.build_query_dependencies()
            selected_provider = dependencies.resolve_model_provider("toy-provider")
            result = dependencies.rerank_hits(
                hits=[{"id": "raw"}],
                user_query="toy",
                provider=provider,
                query_vector=None,
                mode="cosine",
                top_n=1,
                mix_mode=False,
                mix_weight=0.5,
            )

        self.assertIs(selected_provider, provider)
        self.assertIs(result, ranked)
        self.assertIs(dependencies.vector_search_factory, vector_factory)
        self.assertIs(dependencies.graph_search, graph_search)
        provider_factory.assert_called_once_with("toy-provider")
        self.assertEqual(reranker.call_args.kwargs["top_n"], 1)
