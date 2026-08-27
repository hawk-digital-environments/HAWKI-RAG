"""Multimodal provider scenarios for image payloads and explicit vision-model selection."""

from __future__ import annotations

import asyncio
import logging
import os
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch


class RagAnythingVisionTests(unittest.TestCase):
    """Verify RAG-Anything preserves image content and routes it to the configured vision model."""

    def test_graph_runtime_summary_reports_request_provided_models(self) -> None:
        from hawki_indexer_worker.adapters.raganything.settings import (
            load_raganything_graph_settings,
        )
        from hawki_indexer_worker.adapters.raganything.summary import (
            build_graph_runtime_summary,
        )

        with patch.dict(
            os.environ,
            {"NEO4J_URI": "bolt://graph:7687"},
            clear=True,
        ):
            settings = load_raganything_graph_settings()

        provider = SimpleNamespace(
            rag_model="graph-model",
            vision_model="vision-graph",
            embed_model="embed-model",
        )

        with tempfile.TemporaryDirectory() as tmp:
            summary = build_graph_runtime_summary(
                working_dir=Path(tmp),
                settings=settings,
                runtime_meta={},
                graph_client_initialized=False,
                provider=provider,
            )

        self.assertEqual(summary["models"]["vision_model"], "vision-graph")
        self.assertEqual(summary["models"]["graph_model"], "graph-model")
        self.assertEqual(summary["models"]["embed_model"], "embed-model")

    def test_raganything_vision_model_func_preserves_provider_vision_selection(
        self,
    ) -> None:
        from hawki_indexer_worker.adapters.raganything.client_config import (
            _build_vision_model_func,
        )
        from hawki_indexer_worker.adapters.raganything.settings import (
            load_raganything_graph_settings,
        )

        class Provider:
            vision_model = "selected-vision"

            def __init__(self) -> None:
                self.call: dict[str, object] | None = None

            def vision_chat(
                self,
                system: str,
                prompt: str,
                *,
                image_data: str | None = None,
                messages: list | None = None,
                temperature: float | None = None,
            ) -> str:
                self.call = {
                    "system": system,
                    "prompt": prompt,
                    "image_data": image_data,
                    "messages": messages,
                    "temperature": temperature,
                    "vision_model": self.vision_model,
                }
                return "caption"

        with patch.dict(
            os.environ,
            {"GRAPH_TEMPERATURE": "0.2"},
            clear=False,
        ):
            settings = load_raganything_graph_settings()

        provider = Provider()
        vision_model_func = _build_vision_model_func(
            provider,
            settings,
            logger_obj=logging.getLogger("test_raganything_vision"),
        )

        async def direct_to_thread(func, *args, **kwargs):
            return func(*args, **kwargs)

        with patch(
            "hawki_indexer_worker.adapters.raganything.client_config.asyncio.to_thread",
            new=direct_to_thread,
        ):
            response = asyncio.run(
                vision_model_func(
                    "describe image",
                    system_prompt="vision system",
                    image_data="img-base64",
                )
            )

        self.assertEqual(response, "caption")
        self.assertEqual(provider.vision_model, "selected-vision")
        self.assertEqual(provider.call["system"], "vision system")
        self.assertEqual(provider.call["prompt"], "describe image")
        self.assertEqual(provider.call["image_data"], "img-base64")
        self.assertEqual(provider.call["temperature"], 0.2)
        self.assertEqual(provider.call["vision_model"], "selected-vision")


if __name__ == "__main__":
    unittest.main()
