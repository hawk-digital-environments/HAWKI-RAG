"""Live compatibility tests for the optional LiteLLM gateway.

The tests call RAWKI's LiteLLM adapter through advertised local aliases. They
do not fall back to Ollama directly: an unavailable upstream is reported as a
missing integration dependency (or a failure in required/release mode).
"""

from __future__ import annotations

import math
import os
from typing import Any, Callable, NoReturn, TypeVar

import pytest


pytestmark = [pytest.mark.integration, pytest.mark.model]

_Result = TypeVar("_Result")
_UNAVAILABLE_ERROR_TOKENS = (
    "api key",
    "authentication",
    "connection refused",
    "does not exist",
    "failed to resolve",
    "model not found",
    "not installed",
)


def _configured_model(name: str, default: str) -> str:
    return os.environ.get(name, default).strip() or default


def _call_configured_model(
    operation: Callable[[], _Result],
    *,
    description: str,
    unavailable: Callable[[str], NoReturn],
) -> _Result:
    try:
        return operation()
    except RuntimeError as exc:
        safe_message = str(exc)[:300]
        if any(token in safe_message.lower() for token in _UNAVAILABLE_ERROR_TOKENS):
            unavailable(
                f"LiteLLM advertises {description}, but its configured upstream is unavailable: "
                f"{safe_message}"
            )
        raise


def _configure_provider(
    monkeypatch: pytest.MonkeyPatch,
    live_litellm: Any,
    *,
    chat_model: str,
    embed_model: str,
) -> None:
    monkeypatch.setenv("LITELLM_API_URL", live_litellm.api_url)
    monkeypatch.setenv("LITELLM_API_KEY", live_litellm.api_key)
    monkeypatch.setenv("LITELLM_CHAT_MODEL", chat_model)
    monkeypatch.setenv("LITELLM_EMBED_MODEL", embed_model)
    monkeypatch.setenv("LITELLM_VISION_MODEL", chat_model)
    timeout = os.environ.get("RAWKI_INTEGRATION_MODEL_TIMEOUT", "120")
    monkeypatch.setenv("LITELLM_CHAT_TIMEOUT", timeout)
    monkeypatch.setenv("LITELLM_EMBED_TIMEOUT", timeout)


class TestLiveLiteLLMProvider:
    """Prove configured aliases work through LiteLLM's OpenAI-compatible API."""

    def test_embedding_alias_returns_a_finite_nonzero_vector(
        self,
        live_litellm: Any,
        integration_unavailable: Callable[[str], NoReturn],
        monkeypatch: pytest.MonkeyPatch,
    ) -> None:
        from hawki_model_providers.litellm import LiteLLMProvider

        chat_model = _configured_model("LITELLM_CHAT_MODEL", "hawki-ollama-chat")
        embed_model = _configured_model(
            "LITELLM_EMBED_MODEL",
            "hawki-ollama-embedding",
        )
        if not live_litellm.has_model(embed_model):
            integration_unavailable(
                f"LiteLLM model alias '{embed_model}' is not advertised by /v1/models"
            )

        _configure_provider(
            monkeypatch,
            live_litellm,
            chat_model=chat_model,
            embed_model=embed_model,
        )
        provider = LiteLLMProvider()
        vector = _call_configured_model(
            lambda: provider.embed(
                "RAWKI live integration probe routed through the LiteLLM gateway."
            ),
            description=f"embedding alias '{embed_model}'",
            unavailable=integration_unavailable,
        )

        assert provider.base == live_litellm.api_url
        assert provider.embed_model == embed_model
        assert len(vector) > 0
        assert all(math.isfinite(value) for value in vector)
        assert any(abs(value) > 0 for value in vector)

    def test_chat_alias_returns_nonempty_text_without_direct_ollama_fallback(
        self,
        live_litellm: Any,
        integration_unavailable: Callable[[str], NoReturn],
        monkeypatch: pytest.MonkeyPatch,
    ) -> None:
        from hawki_model_providers.litellm import LiteLLMProvider

        chat_model = _configured_model("LITELLM_CHAT_MODEL", "hawki-ollama-chat")
        embed_model = _configured_model(
            "LITELLM_EMBED_MODEL",
            "hawki-ollama-embedding",
        )
        if not live_litellm.has_model(chat_model):
            integration_unavailable(
                f"LiteLLM model alias '{chat_model}' is not advertised by /v1/models"
            )

        _configure_provider(
            monkeypatch,
            live_litellm,
            chat_model=chat_model,
            embed_model=embed_model,
        )
        provider = LiteLLMProvider()
        answer = _call_configured_model(
            lambda: provider.chat(
                "Reply with one short word.",
                [{"role": "user", "content": "Is the LiteLLM route working?"}],
                temperature=0.0,
            ),
            description=f"chat alias '{chat_model}'",
            unavailable=integration_unavailable,
        )

        assert provider.base == live_litellm.api_url
        assert provider.rag_model == chat_model
        assert isinstance(answer, str)
        assert answer.strip()
