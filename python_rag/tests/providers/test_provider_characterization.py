"""Provider-boundary scenarios for graph models and dependency-injected RAG services."""

from __future__ import annotations

import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch


class GraphProviderCharacterizationTests(unittest.TestCase):
    """Show how an explicit graph model is isolated from the main provider configuration."""

    def test_graph_provider_helper_clones_and_applies_explicit_graph_model(
        self,
    ) -> None:
        from hawki_indexer_worker.adapters.raganything.provider_config import (
            clone_provider_for_graph,
            provider_fingerprint,
        )

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


class ServiceBoundaryCharacterizationTests(unittest.TestCase):
    """Show how the former facade is split across indexer and bridge boundaries."""

    def test_indexer_graph_facade_delegates_to_the_graph_service_boundary(self) -> None:
        from hawki_indexer_worker.adapters.providers.graph import GraphExtractionFacade

        provider = {"provider": "toy-provider"}
        fake_graph_service = object()
        calls: list[dict[str, object]] = []

        def extract_triplets(
            graph_service: object,
            text: str,
            engine: str | None,
            **kwargs: object,
        ) -> list[tuple[str, str, str]]:
            calls.append(
                {
                    "graph_service": graph_service,
                    "text": text,
                    "engine": engine,
                    **kwargs,
                }
            )
            return [("Toy", "has", "Wheels")]

        with (
            tempfile.TemporaryDirectory() as tmp,
            patch(
                "hawki_indexer_worker.adapters.providers.graph.RagAnythingGraphService",
                return_value=fake_graph_service,
            ) as graph_service_factory,
            patch(
                "hawki_indexer_worker.adapters.providers.graph.extract_triplets_with_graph_service",
                side_effect=extract_triplets,
            ),
        ):
            facade = GraphExtractionFacade(Path(tmp), graph_perf_log=True)
            result = facade.extract_triplets(
                "toy text",
                None,
                provider=provider,
                doc_id="toy-doc",
                request_id="request-1",
            )

        self.assertEqual(result, [("Toy", "has", "Wheels")])
        graph_service_factory.assert_called_once_with(Path(tmp), logger_obj=None)
        self.assertIs(calls[0]["graph_service"], fake_graph_service)
        self.assertEqual(calls[0]["text"], "toy text")
        self.assertIsNone(calls[0]["engine"])
        self.assertIs(calls[0]["provider"], provider)
        self.assertEqual(calls[0]["doc_id"], "toy-doc")
        self.assertEqual(calls[0]["request_id"], "request-1")
        self.assertTrue(calls[0]["graph_perf_log"])

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
                composition, "get_provider", return_value=provider
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
