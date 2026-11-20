"""Ollama multimodal payload and explicit vision-model behavior."""

from __future__ import annotations

import os
import unittest
from unittest.mock import patch


class _FakeResponse:
    ok = True
    status_code = 200
    text = ""

    def json(self) -> dict[str, object]:
        return {"message": {"content": "caption"}}

    def raise_for_status(self) -> None:
        return None


class _FakeRequests:
    def __init__(self) -> None:
        self.posts: list[dict[str, object]] = []

    def post(
        self, url: str, *, json: dict[str, object], timeout: float
    ) -> _FakeResponse:
        self.posts.append({"url": url, "json": json, "timeout": timeout})
        return _FakeResponse()


class _FallbackResponse:
    text = ""

    def __init__(self, status_code: int, payload: dict[str, object]) -> None:
        self.status_code = status_code
        self.payload = payload
        self.ok = 200 <= status_code < 300

    def json(self) -> dict[str, object]:
        return self.payload

    def raise_for_status(self) -> None:
        return None


class _FallbackRequests:
    def __init__(self, responses: list[_FallbackResponse]) -> None:
        self.responses = list(responses)
        self.posts: list[dict[str, object]] = []

    def post(
        self, url: str, *, json: dict[str, object], timeout: float
    ) -> _FallbackResponse:
        self.posts.append({"url": url, "json": json, "timeout": timeout})
        return self.responses.pop(0)


class OllamaVisionTests(unittest.TestCase):
    def test_chat_preserves_legacy_generate_endpoint_fallback_order(self) -> None:
        from hawki_model_providers.ollama import OllamaProvider

        fake_requests = _FallbackRequests(
            [
                _FallbackResponse(404, {"error": "route not found"}),
                _FallbackResponse(404, {"error": "route not found"}),
                _FallbackResponse(200, {"response": "legacy answer"}),
            ]
        )

        with patch.dict(
            os.environ,
            {
                "OLLAMA_API_URL": "http://ollama:11434/api",
                "OLLAMA_CHAT_RETRIES": "0",
                "OLLAMA_CHAT_BACKOFF": "0",
            },
            clear=False,
        ):
            provider = OllamaProvider(http_client=fake_requests)
            provider.rag_model = "chat-test"
            answer = provider.chat(
                "Use the supplied context.",
                [{"role": "user", "content": "Question"}],
            )

        self.assertEqual(answer, "legacy answer")
        self.assertEqual(
            [request["url"] for request in fake_requests.posts],
            [
                "http://ollama:11434/api/chat",
                "http://ollama:11434/api/generate",
                "http://ollama:11434/generate",
            ],
        )

    def test_ollama_provider_sends_image_data_to_configured_vision_model(self) -> None:
        from hawki_model_providers.ollama import OllamaProvider

        fake_requests = _FakeRequests()

        with patch.dict(
            os.environ,
            {
                "OLLAMA_API_URL": "http://ollama:11434/api",
                "OLLAMA_CHAT_RETRIES": "0",
                "OLLAMA_CHAT_TIMEOUT": "12",
            },
            clear=False,
        ):
            provider = OllamaProvider(http_client=fake_requests)
            provider.vision_model = "vision-test"
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
        self.assertEqual(
            payload["messages"][0], {"role": "system", "content": "system prompt"}
        )
        self.assertEqual(payload["messages"][1]["content"], "describe this image")
        self.assertEqual(payload["messages"][1]["images"], ["abc123"])
        self.assertEqual(payload["options"]["temperature"], 0.1)

    def test_ollama_provider_normalizes_raganything_multimodal_messages(self) -> None:
        from hawki_model_providers.ollama import OllamaProvider

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
            {"OLLAMA_CHAT_RETRIES": "0"},
            clear=False,
        ):
            provider = OllamaProvider(http_client=fake_requests)
            provider.vision_model = "vision-test"
            provider.vision_chat("fallback system", "", messages=messages)

        payload = fake_requests.posts[0]["json"]
        self.assertEqual(
            payload["messages"][0], {"role": "system", "content": "existing system"}
        )
        self.assertEqual(payload["messages"][1]["content"], "Context before image.")
        self.assertEqual(payload["messages"][1]["images"], ["xyz789"])
