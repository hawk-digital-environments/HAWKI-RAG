"""LiteLLM adapter scenarios for model selection, OpenAI-compatible payloads, and safe failures."""

from __future__ import annotations

import os
import unittest
from typing import Any
from unittest.mock import patch


class _RequestException(Exception):
    pass


class _Timeout(_RequestException):
    pass


class _FakeResponse:
    def __init__(
        self, payload: Any, *, status_code: int = 200, invalid_json: bool = False
    ) -> None:
        self.payload = payload
        self.status_code = status_code
        self.invalid_json = invalid_json

    def json(self) -> Any:
        if self.invalid_json:
            raise ValueError("invalid JSON")
        return self.payload


class _FakeRequests:
    class exceptions:
        RequestException = _RequestException
        Timeout = _Timeout

    def __init__(
        self, *responses: _FakeResponse, error: Exception | None = None
    ) -> None:
        self.responses = list(responses)
        self.error = error
        self.posts: list[dict[str, Any]] = []

    def post(
        self,
        url: str,
        *,
        json: dict[str, Any],
        headers: dict[str, str],
        timeout: float,
    ) -> _FakeResponse:
        self.posts.append(
            {
                "url": url,
                "json": json,
                "headers": headers,
                "timeout": timeout,
            }
        )
        if self.error is not None:
            raise self.error
        if not self.responses:
            raise AssertionError("No fake response configured")
        return self.responses.pop(0)


def _provider_env(**overrides: str) -> dict[str, str]:
    values = {
        "LITELLM_API_URL": "http://127.0.0.1:4000/v1/",
        "LITELLM_API_KEY": "test-secret-key",
        "LITELLM_CHAT_TIMEOUT": "12.5",
        "LITELLM_EMBED_TIMEOUT": "7",
        "LITELLM_TEMPERATURE": "0.2",
    }
    values.update(overrides)
    return values


def _configured(provider: Any) -> Any:
    """Attach the request-provided models Laravel would send."""

    provider.embed_model = "test-embedding"
    provider.rag_model = "test-chat"
    provider.vision_model = "test-vision"
    return provider


class LiteLLMProviderTests(unittest.TestCase):
    """Verify LiteLLM remains explicit, validates responses, and never exposes credentials."""

    def test_factory_selects_litellm_without_changing_ollama(self) -> None:
        from hawki_model_providers.factory import create_model_provider
        from hawki_model_providers.litellm import LiteLLMProvider
        from hawki_model_providers.ollama import OllamaProvider

        with patch.dict(os.environ, _provider_env(), clear=True):
            litellm = create_model_provider(" LITELLM ")
            ollama = create_model_provider("ollama")

        self.assertIsInstance(litellm, LiteLLMProvider)
        self.assertIsInstance(ollama, OllamaProvider)

    def test_configuration_rejects_invalid_url_model_and_timeout(self) -> None:
        from hawki_model_providers.litellm import (
            LiteLLMConfigurationError,
            LiteLLMProvider,
        )

        invalid_settings = [
            (
                {"LITELLM_API_URL": "ftp://litellm.example/v1"},
                "LITELLM_API_URL must be an absolute",
            ),
            (
                {"LITELLM_API_URL": "https://user:password@litellm.example/v1"},
                "must not contain credentials",
            ),
            (
                {"LITELLM_EMBED_TIMEOUT": "nan"},
                "LITELLM_EMBED_TIMEOUT must be a positive",
            ),
        ]

        for overrides, expected_message in invalid_settings:
            with self.subTest(overrides=overrides):
                with patch.dict(os.environ, _provider_env(**overrides), clear=True):
                    with self.assertRaisesRegex(
                        LiteLLMConfigurationError, expected_message
                    ):
                        LiteLLMProvider()

    def test_calls_without_request_provided_models_fail_loudly(self) -> None:
        from hawki_model_providers.litellm import LiteLLMProvider

        with patch.dict(os.environ, _provider_env(), clear=True):
            provider = LiteLLMProvider()

        self.assertIsNone(provider.embed_model)
        self.assertIsNone(provider.rag_model)
        self.assertIsNone(provider.vision_model)
        with self.assertRaisesRegex(RuntimeError, "embed_model.*not set"):
            provider.embed("input")
        with self.assertRaisesRegex(RuntimeError, "rag_model.*not set"):
            provider.chat("system", [])
        with self.assertRaisesRegex(RuntimeError, "vision_model.*not set"):
            provider.vision_chat("system", "prompt")

    def test_chat_sends_openai_payload_and_parses_content(self) -> None:
        from hawki_model_providers.litellm import LiteLLMProvider

        fake_requests = _FakeRequests(
            _FakeResponse({"choices": [{"message": {"content": "Grounded answer"}}]})
        )
        with (
            patch.dict(os.environ, _provider_env(), clear=True),
            patch(
                "hawki_model_providers.litellm._requests_module",
                return_value=fake_requests,
            ),
        ):
            provider = _configured(LiteLLMProvider())
            answer = provider.chat(
                "Answer from supplied context.",
                [{"role": "user", "content": "What is RAWKI?"}],
                temperature=0.0,
            )

        self.assertEqual(answer, "Grounded answer")
        self.assertEqual(len(fake_requests.posts), 1)
        request = fake_requests.posts[0]
        self.assertEqual(request["url"], "http://127.0.0.1:4000/v1/chat/completions")
        self.assertEqual(request["timeout"], 12.5)
        self.assertEqual(request["headers"]["Authorization"], "Bearer test-secret-key")
        self.assertEqual(request["json"]["model"], "test-chat")
        self.assertEqual(request["json"]["temperature"], 0.0)
        self.assertFalse(request["json"]["stream"])
        self.assertEqual(
            request["json"]["messages"],
            [
                {"role": "system", "content": "Answer from supplied context."},
                {"role": "user", "content": "What is RAWKI?"},
            ],
        )

    def test_chat_parses_openai_structured_text_content(self) -> None:
        from hawki_model_providers.litellm import LiteLLMProvider

        fake_requests = _FakeRequests(
            _FakeResponse(
                {
                    "choices": [
                        {
                            "message": {
                                "content": [
                                    {"type": "text", "text": "Part one. "},
                                    {"type": "text", "text": "Part two."},
                                ]
                            }
                        }
                    ]
                }
            )
        )
        with (
            patch.dict(os.environ, _provider_env(), clear=True),
            patch(
                "hawki_model_providers.litellm._requests_module",
                return_value=fake_requests,
            ),
        ):
            answer = _configured(LiteLLMProvider()).chat("system", [])

        self.assertEqual(answer, "Part one. Part two.")

    def test_embed_sends_model_and_returns_finite_float_vector(self) -> None:
        from hawki_model_providers.litellm import LiteLLMProvider

        fake_requests = _FakeRequests(
            _FakeResponse({"data": [{"index": 0, "embedding": [0, "1.25", -2.5]}]})
        )
        with (
            patch.dict(os.environ, _provider_env(), clear=True),
            patch(
                "hawki_model_providers.litellm._requests_module",
                return_value=fake_requests,
            ),
        ):
            provider = _configured(LiteLLMProvider())
            vector = provider.embed("Embedding input")

        self.assertEqual(vector, [0.0, 1.25, -2.5])
        self.assertEqual(provider._last_embed_dim, 3)
        request = fake_requests.posts[0]
        self.assertEqual(request["url"], "http://127.0.0.1:4000/v1/embeddings")
        self.assertEqual(request["timeout"], 7.0)
        self.assertEqual(
            request["json"],
            {"model": "test-embedding", "input": "Embedding input"},
        )

    def test_embed_rejects_empty_and_non_finite_vectors(self) -> None:
        from hawki_model_providers.litellm import LiteLLMProvider

        responses = [
            (_FakeResponse({"data": []}), "unexpected response"),
            (
                _FakeResponse({"data": [{"embedding": [0.1, float("nan")]}]}),
                "non-finite vector value",
            ),
        ]
        for response, expected_message in responses:
            with self.subTest(expected_message=expected_message):
                fake_requests = _FakeRequests(response)
                with (
                    patch.dict(os.environ, _provider_env(), clear=True),
                    patch(
                        "hawki_model_providers.litellm._requests_module",
                        return_value=fake_requests,
                    ),
                ):
                    with self.assertRaisesRegex(RuntimeError, expected_message):
                        _configured(LiteLLMProvider()).embed("input")

    def test_vision_chat_uses_openai_image_url_shape_and_vision_model(self) -> None:
        from hawki_model_providers.litellm import LiteLLMProvider

        fake_requests = _FakeRequests(
            _FakeResponse({"choices": [{"message": {"content": "A diagram"}}]})
        )
        with (
            patch.dict(os.environ, _provider_env(), clear=True),
            patch(
                "hawki_model_providers.litellm._requests_module",
                return_value=fake_requests,
            ),
        ):
            answer = _configured(LiteLLMProvider()).vision_chat(
                "Describe only visible details.",
                "What is shown?",
                image_data="abc123",
                temperature=0.1,
            )

        self.assertEqual(answer, "A diagram")
        payload = fake_requests.posts[0]["json"]
        self.assertEqual(payload["model"], "test-vision")
        self.assertEqual(payload["messages"][0]["role"], "system")
        self.assertEqual(
            payload["messages"][1]["content"],
            [
                {"type": "text", "text": "What is shown?"},
                {
                    "type": "image_url",
                    "image_url": {"url": "data:image/png;base64,abc123"},
                },
            ],
        )

    def test_http_and_timeout_errors_are_normalized_without_exposing_api_key(
        self,
    ) -> None:
        from hawki_model_providers.litellm import LiteLLMProvider

        http_requests = _FakeRequests(
            _FakeResponse(
                {"error": {"message": "Rejected Bearer test-secret-key"}},
                status_code=401,
            )
        )
        with (
            patch.dict(os.environ, _provider_env(), clear=True),
            patch(
                "hawki_model_providers.litellm._requests_module",
                return_value=http_requests,
            ),
        ):
            with self.assertRaisesRegex(RuntimeError, r"HTTP error \(401\)") as raised:
                _configured(LiteLLMProvider()).chat("system", [])

        self.assertNotIn("test-secret-key", str(raised.exception))
        self.assertIn("<redacted>", str(raised.exception))

        timeout_requests = _FakeRequests(error=_Timeout("gateway timeout"))
        with (
            patch.dict(os.environ, _provider_env(), clear=True),
            patch(
                "hawki_model_providers.litellm._requests_module",
                return_value=timeout_requests,
            ),
        ):
            with self.assertRaisesRegex(
                RuntimeError, "request timed out: gateway timeout"
            ):
                _configured(LiteLLMProvider()).embed("input")

    def test_success_response_must_be_json_with_expected_shape(self) -> None:
        from hawki_model_providers.litellm import LiteLLMProvider

        invalid_json = _FakeRequests(_FakeResponse(None, invalid_json=True))
        with (
            patch.dict(os.environ, _provider_env(), clear=True),
            patch(
                "hawki_model_providers.litellm._requests_module",
                return_value=invalid_json,
            ),
        ):
            with self.assertRaisesRegex(RuntimeError, "returned a non-JSON response"):
                _configured(LiteLLMProvider()).chat("system", [])

        wrong_shape = _FakeRequests(_FakeResponse({"choices": []}))
        with (
            patch.dict(os.environ, _provider_env(), clear=True),
            patch(
                "hawki_model_providers.litellm._requests_module",
                return_value=wrong_shape,
            ),
        ):
            with self.assertRaisesRegex(RuntimeError, "unexpected or empty response"):
                _configured(LiteLLMProvider()).chat("system", [])


if __name__ == "__main__":
    unittest.main()
