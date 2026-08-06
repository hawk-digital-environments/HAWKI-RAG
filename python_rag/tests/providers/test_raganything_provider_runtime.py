"""RAG-Anything provider-runtime scenarios for model overrides and embedding dimensions."""

from __future__ import annotations

import os
import unittest
from types import SimpleNamespace
from unittest.mock import patch


class RagAnythingProviderRuntimeTests(unittest.TestCase):
    """Verify graph extraction uses the selected provider without changing vector compatibility."""

    def test_graph_clone_preserves_request_models_and_observed_embedding_dimension(
        self,
    ) -> None:
        from hawki_indexer_worker.adapters.raganything.provider_config import (
            clone_provider_for_graph,
        )
        from hawki_model_providers.overrides import apply_provider_overrides

        class Provider:
            def __init__(self) -> None:
                self.base = "http://litellm:4000/v1"
                self.key = ""
                self.rag_model = "hawki-chat"
                self.embed_model = "hawki-embedding"
                self.vision_model = "hawki-vision"
                self._last_embed_dim = None

        provider = Provider()
        provider._last_embed_dim = 1024
        apply_provider_overrides(
            provider,
            SimpleNamespace(
                embedding_model="hawki-openai-embedding",
                graph_model="hawki-gpt-chat",
                vision_model="hawki-gpt-vision",
            ),
        )
        self.assertIsNone(provider._last_embed_dim)
        provider._last_embed_dim = 1536

        with patch.dict(
            os.environ,
            {"GRAPH_OLLAMA_VISION_MODEL": "legacy-ollama-vision"},
            clear=False,
        ):
            graph_provider = clone_provider_for_graph(provider)

        self.assertIsNot(graph_provider, provider)
        self.assertEqual(graph_provider.rag_model, "hawki-gpt-chat")
        self.assertEqual(graph_provider.embed_model, "hawki-openai-embedding")
        self.assertEqual(graph_provider.vision_model, "hawki-gpt-vision")
        self.assertEqual(graph_provider._explicit_vision_model, "hawki-gpt-vision")
        self.assertEqual(graph_provider._last_embed_dim, 1536)

    def test_observed_embedding_dimension_takes_priority_over_alias_configuration(
        self,
    ) -> None:
        from hawki_indexer_worker.adapters.raganything.client_config import (
            _embed_model_dim,
        )
        from hawki_indexer_worker.adapters.raganything.settings import (
            load_raganything_graph_settings,
        )

        with patch.dict(
            os.environ,
            {"GRAPH_EMBEDDING_DIMENSIONS": "hawki-openai-embedding=1536"},
            clear=True,
        ):
            settings = load_raganything_graph_settings()

        provider = SimpleNamespace(
            embed_model="hawki-openai-embedding",
            _last_embed_dim=768,
        )

        self.assertEqual(_embed_model_dim(provider, settings), 768)

    def test_default_graph_only_alias_dimensions_cover_litellm_embeddings(self) -> None:
        from hawki_indexer_worker.adapters.raganything.client_config import (
            _embed_model_dim,
        )
        from hawki_indexer_worker.adapters.raganything.settings import (
            load_raganything_graph_settings,
        )

        with patch.dict(os.environ, {}, clear=True):
            settings = load_raganything_graph_settings()

        local_provider = SimpleNamespace(
            embed_model="hawki-ollama-embedding",
            _last_embed_dim=None,
        )
        openai_provider = SimpleNamespace(
            embed_model="hawki-openai-embedding",
            _last_embed_dim=None,
        )

        self.assertEqual(_embed_model_dim(local_provider, settings), 1024)
        self.assertEqual(_embed_model_dim(openai_provider, settings), 1536)

    def test_embedding_dimension_alias_parser_rejects_unsafe_entries(self) -> None:
        from hawki_indexer_worker.adapters.raganything.client_config import (
            _embedding_dimension_overrides,
        )

        dimensions = _embedding_dimension_overrides(
            "valid-alias=2048,unsafe alias=1536,too-large=65537,"
            "zero=0,not-a-number=large"
        )

        self.assertEqual(dimensions, {"valid-alias": 2048})


if __name__ == "__main__":
    unittest.main()
