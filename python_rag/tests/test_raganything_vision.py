from __future__ import annotations

import asyncio
import logging
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))


class _FakeResponse:
    ok = True
    status_code = 200
    text = ""

    def json(self) -> dict[str, object]:
        return {"message": {"content": "caption"}}


class _FakeRequests:
    class exceptions:
        class HTTPError(Exception):
            pass

        class RequestException(Exception):
            pass

        class Timeout(RequestException):
            pass

    def __init__(self) -> None:
        self.posts: list[dict[str, object]] = []

    def post(self, url: str, *, json: dict[str, object], timeout: float) -> _FakeResponse:
        self.posts.append({"url": url, "json": json, "timeout": timeout})
        return _FakeResponse()


class RagAnythingVisionTests(unittest.TestCase):
    def test_ollama_provider_sends_image_data_to_configured_vision_model(self) -> None:
        from infrastructure.providers.ollama_provider import OllamaProvider

        fake_requests = _FakeRequests()

        with patch.dict(
            os.environ,
            {
                "OLLAMA_API_URL": "http://ollama:11434/api",
                "OLLAMA_VISION_MODEL": "vision-test",
                "OLLAMA_CHAT_RETRIES": "0",
                "OLLAMA_CHAT_TIMEOUT": "12",
            },
            clear=False,
        ), patch(
            "infrastructure.providers.ollama_provider._requests_module",
            return_value=fake_requests,
        ):
            provider = OllamaProvider()
            response = provider.vision_chat(
                "system prompt",
                "describe this image",
                image_data="data:image/png;base64,abc123",
                temperature=0.1,
            )

        self.assertEqual(response, "caption")
        self.assertEqual(len(fake_requests.posts), 1)
        payload = fake_requests.posts[0]["json"]
        self.assertEqual(payload["model"], "vision-test")
        self.assertEqual(fake_requests.posts[0]["timeout"], 12.0)
        self.assertEqual(payload["messages"][0], {"role": "system", "content": "system prompt"})
        self.assertEqual(payload["messages"][1]["content"], "describe this image")
        self.assertEqual(payload["messages"][1]["images"], ["abc123"])
        self.assertEqual(payload["options"]["temperature"], 0.1)

    def test_ollama_provider_normalizes_raganything_multimodal_messages(self) -> None:
        from infrastructure.providers.ollama_provider import OllamaProvider

        fake_requests = _FakeRequests()
        messages = [
            {"role": "system", "content": "existing system"},
            {
                "role": "user",
                "content": [
                    {"type": "text", "text": "Context before image."},
                    {
                        "type": "image_url",
                        "image_url": {"url": "data:image/jpeg;base64,xyz789"},
                    },
                ],
            },
        ]

        with patch.dict(
            os.environ,
            {"OLLAMA_VISION_MODEL": "vision-test", "OLLAMA_CHAT_RETRIES": "0"},
            clear=False,
        ), patch(
            "infrastructure.providers.ollama_provider._requests_module",
            return_value=fake_requests,
        ):
            provider = OllamaProvider()
            provider.vision_chat("fallback system", "", messages=messages)

        payload = fake_requests.posts[0]["json"]
        self.assertEqual(payload["messages"][0], {"role": "system", "content": "existing system"})
        self.assertEqual(payload["messages"][1]["content"], "Context before image.")
        self.assertEqual(payload["messages"][1]["images"], ["xyz789"])

    def test_raganything_settings_and_summary_include_vision_model(self) -> None:
        from infrastructure.raganything.raganything_settings import load_raganything_graph_settings
        from infrastructure.raganything.raganything_summary import build_graph_runtime_summary

        with patch.dict(
            os.environ,
            {
                "GRAPH_OLLAMA_VISION_MODEL": "vision-graph",
                "OLLAMA_VISION_MODEL": "vision-general",
                "NEO4J_URI": "bolt://graph:7687",
            },
            clear=True,
        ):
            settings = load_raganything_graph_settings()

        self.assertEqual(settings.vision_model, "vision-graph")

        with tempfile.TemporaryDirectory() as tmp:
            summary = build_graph_runtime_summary(
                working_dir=Path(tmp),
                settings=settings,
                runtime_meta={},
                graph_client_initialized=False,
            )

        self.assertEqual(summary["models"]["vision_model"], "vision-graph")

    def test_raganything_vision_model_func_preserves_provider_vision_selection(self) -> None:
        from infrastructure.raganything.raganything_client_config import _build_vision_model_func
        from infrastructure.raganything.raganything_settings import load_raganything_graph_settings

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
            {"GRAPH_OLLAMA_VISION_MODEL": "vision-graph", "GRAPH_TEMPERATURE": "0.2"},
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
            "infrastructure.raganything.raganything_client_config.asyncio.to_thread",
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
        self.assertEqual(settings.vision_model, "vision-graph")
        self.assertEqual(provider.vision_model, "selected-vision")
        self.assertEqual(provider.call["system"], "vision system")
        self.assertEqual(provider.call["prompt"], "describe image")
        self.assertEqual(provider.call["image_data"], "img-base64")
        self.assertEqual(provider.call["temperature"], 0.2)
        self.assertEqual(provider.call["vision_model"], "selected-vision")


if __name__ == "__main__":
    unittest.main()
