"""LiteLLM SDK provider routing, payloads, and response handling."""

from __future__ import annotations

from typing import Any

import pytest

from hawki_model_providers import factory
from hawki_model_providers.litellm_sdk import LiteLLMSDKProvider


class _Recording:
    def __init__(self, result: Any) -> None:
        self.result = result
        self.calls: list[dict[str, Any]] = []


class _FakeEmbedding(_Recording):
    def __call__(self, *, model: str, input: list[str], **kwargs: Any) -> Any:
        self.calls.append({"model": model, "input": input, **kwargs})

        class _Data:
            embedding = self.result

        class _Response:
            data = [_Data()]

        return _Response()


class _FakeCompletion(_Recording):
    def __call__(self, *, model: str, messages: list, **kwargs: Any) -> Any:
        self.calls.append({"model": model, "messages": messages, **kwargs})

        class _Message:
            content = self.result

        class _Choice:
            message = _Message()

        class _Response:
            choices = [_Choice()]

        return _Response()


@pytest.fixture(autouse=True)
def _clean_env(monkeypatch: pytest.MonkeyPatch) -> None:
    for name in (
        "OLLAMA_KEEP_ALIVE",
        "OLLAMA_API_URL",
        "OLLAMA_EMBED_TIMEOUT",
        "OLLAMA_EMBED_MAX_CHARS",
        "OLLAMA_EMBED_NAN_ZERO_FALLBACK",
        "OLLAMA_CHAT_TIMEOUT",
        "OLLAMA_CHAT_RETRIES",
        "OLLAMA_CHAT_BACKOFF",
        "LITELLM_API_URL",
        "LITELLM_API_KEY",
    ):
        monkeypatch.delenv(name, raising=False)


def _provider(provider_name: str = "ollama") -> LiteLLMSDKProvider:
    provider = LiteLLMSDKProvider(provider_name)
    provider.embed_model = "bge-m3"
    provider.rag_model = "llama3.1:8b"
    provider.vision_model = "qwen2.5vl:7b"
    return provider


def test_embed_routes_unprefixed_ollama_models_and_finds_base(monkeypatch) -> None:
    fake = _FakeEmbedding([0.1, 0.2])
    monkeypatch.setattr("hawki_model_providers.litellm_sdk.litellm.embedding", fake)
    monkeypatch.setenv("OLLAMA_API_URL", "http://hawki_ollama:11434/api")
    provider = _provider()

    vector = provider.embed("warm it")

    assert vector == [0.1, 0.2]
    call = fake.calls[0]
    assert call["model"] == "ollama/bge-m3"
    assert call["api_base"] == "http://hawki_ollama:11434"
    assert call["input"] == ["warm it"]


def test_embed_keeps_canonical_prefixed_models(monkeypatch) -> None:
    fake = _FakeEmbedding([0.5])
    monkeypatch.setattr("hawki_model_providers.litellm_sdk.litellm.embedding", fake)
    provider = _provider()
    provider.embed_model = "openai/text-embedding-3-small"

    provider.embed("warm it")

    assert fake.calls[0]["model"] == "openai/text-embedding-3-small"


def test_embed_passes_keep_alive_on_the_ollama_route(monkeypatch) -> None:
    fake = _FakeEmbedding([0.1])
    monkeypatch.setattr("hawki_model_providers.litellm_sdk.litellm.embedding", fake)
    monkeypatch.setenv("OLLAMA_KEEP_ALIVE", "24h")

    _provider().embed("warm it")

    assert fake.calls[0]["keep_alive"] == "24h"


def test_embed_skips_keep_alive_for_litellm_proxy(monkeypatch) -> None:
    fake = _FakeEmbedding([0.1])
    monkeypatch.setattr("hawki_model_providers.litellm_sdk.litellm.embedding", fake)
    monkeypatch.setenv("OLLAMA_KEEP_ALIVE", "24h")
    provider = _provider("litellm")
    provider.embed_model = "hawki-ollama-embedding"

    provider.embed("warm it")

    assert "keep_alive" not in fake.calls[0]
    assert fake.calls[0]["model"] == "hawki-ollama-embedding"


def test_embed_sanitizes_non_finite_components(monkeypatch) -> None:
    fake = _FakeEmbedding([float("nan"), 0.3])
    monkeypatch.setattr("hawki_model_providers.litellm_sdk.litellm.embedding", fake)

    assert _provider().embed("warm it") == [0.0, 0.3]


def test_chat_routes_ollama_models_through_ollama_chat(monkeypatch) -> None:
    fake = _FakeCompletion("OK")
    monkeypatch.setattr("hawki_model_providers.litellm_sdk.litellm.completion", fake)

    reply = _provider().chat("You are a probe.", [{"role": "user", "content": "hi"}])

    assert reply == "OK"
    call = fake.calls[0]
    assert call["model"] == "ollama_chat/llama3.1:8b"
    assert call["messages"][0] == {"role": "system", "content": "You are a probe."}
    assert call["stream"] is False


def test_chat_rejects_empty_replies(monkeypatch) -> None:
    fake = _FakeCompletion("  ")
    monkeypatch.setattr("hawki_model_providers.litellm_sdk.litellm.completion", fake)

    with pytest.raises(RuntimeError, match="empty reply"):
        _provider().chat("s", [{"role": "user", "content": "q"}])


def test_vision_chat_builds_image_content(monkeypatch) -> None:
    fake = _FakeCompletion("OK")
    monkeypatch.setattr("hawki_model_providers.litellm_sdk.litellm.completion", fake)

    _provider().vision_chat("s", "describe", image_data="data:image/png;base64,QUJD")

    call = fake.calls[0]
    assert call["model"] == "ollama_chat/qwen2.5vl:7b"
    user = call["messages"][-1]
    assert user["role"] == "user"
    text_part, image_part = user["content"]
    assert text_part == {"type": "text", "text": "describe"}
    assert image_part["image_url"]["url"].endswith("QUJD")


def test_models_are_required_explicitly() -> None:
    provider = LiteLLMSDKProvider("ollama")

    with pytest.raises(RuntimeError, match="embed_model"):
        provider.embed("warm it")


def test_embed_cleans_the_prompt_before_sending(monkeypatch) -> None:
    fake = _FakeEmbedding([0.1])
    monkeypatch.setattr("hawki_model_providers.litellm_sdk.litellm.embedding", fake)

    _provider().embed("a\x00b\n\n\nc  spaced")

    assert fake.calls[0]["input"] == ["ab\n\nc spaced"]


def test_embed_falls_back_to_zero_vector_on_nan_serialization_failure(
    monkeypatch,
) -> None:
    def _boom(**kwargs: Any) -> Any:
        raise ValueError("json: cannot serialize NaN embedding value")

    monkeypatch.setattr("hawki_model_providers.litellm_sdk.litellm.embedding", _boom)

    provider = _provider()
    provider.embed_model = "bge-m3"

    assert provider.embed("warm it") == [0.0] * 1024


def test_embed_reraises_when_zero_fallback_is_disabled(monkeypatch) -> None:
    def _boom(**kwargs: Any) -> Any:
        raise ValueError("json: cannot serialize NaN embedding value")

    monkeypatch.setattr("hawki_model_providers.litellm_sdk.litellm.embedding", _boom)
    monkeypatch.setenv("OLLAMA_EMBED_NAN_ZERO_FALLBACK", "false")

    with pytest.raises(RuntimeError, match="embedding failed"):
        _provider().embed("warm it")


def test_chat_retries_transient_failures_with_backoff(monkeypatch) -> None:
    attempts: list[int] = []

    class _Flaky:
        def __call__(self, *, model: str, messages: list, **kwargs: Any) -> Any:
            attempts.append(1)
            if len(attempts) < 3:
                raise TimeoutError("upstream slow")
            return _FakeCompletion("OK").__call__(
                model=model, messages=messages, **kwargs
            )

    fake = _Flaky()
    monkeypatch.setattr("hawki_model_providers.litellm_sdk.litellm.completion", fake)
    monkeypatch.setenv("OLLAMA_CHAT_RETRIES", "2")
    monkeypatch.setenv("OLLAMA_CHAT_BACKOFF", "0")

    assert _provider().chat("s", [{"role": "user", "content": "q"}]) == "OK"
    assert len(attempts) == 3


def test_factory_returns_the_sdk_client_for_public_names(monkeypatch) -> None:
    assert isinstance(factory.create_model_provider("ollama"), LiteLLMSDKProvider)
    assert isinstance(factory.create_model_provider("litellm"), LiteLLMSDKProvider)


def test_factory_rejects_unknown_providers() -> None:
    with pytest.raises(ValueError, match="Unknown provider"):
        factory.create_model_provider("openai")
