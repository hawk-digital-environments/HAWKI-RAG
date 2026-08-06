"""Live compatibility tests for the direct Ollama provider.

These tests use only models that are already installed. They never call
``ollama pull`` and keep generation deliberately short.
"""

from __future__ import annotations

import math
import os
from typing import Any, Callable, NoReturn

import pytest


pytestmark = [pytest.mark.integration, pytest.mark.model]


def _configured_model(name: str, default: str) -> str:
    return os.environ.get(name, default).strip() or default


class TestLiveOllamaProvider:
    """Prove RAWKI's adapter works with a reachable, installed Ollama model."""

    def test_embedding_model_returns_a_finite_nonzero_vector(
        self,
        live_ollama: Any,
        integration_unavailable: Callable[[str], NoReturn],
        monkeypatch: pytest.MonkeyPatch,
    ) -> None:
        from hawki_model_providers.ollama import OllamaProvider

        model = _configured_model("OLLAMA_EMBED_MODEL", "bge-m3")
        if not live_ollama.has_model(model):
            integration_unavailable(
                f"Ollama embedding model '{model}' is not installed; run `ollama pull {model}`"
            )

        monkeypatch.setenv("OLLAMA_API_URL", live_ollama.api_url)
        monkeypatch.setenv("OLLAMA_EMBED_MODEL", model)
        monkeypatch.setenv(
            "OLLAMA_EMBED_TIMEOUT",
            os.environ.get("RAWKI_INTEGRATION_MODEL_TIMEOUT", "120"),
        )
        monkeypatch.setenv("OLLAMA_EMBED_NAN_ZERO_FALLBACK", "false")

        vector = OllamaProvider().embed(
            "RAWKI live integration probe for multilingual dataset retrieval."
        )

        assert len(vector) > 0
        assert all(math.isfinite(value) for value in vector)
        assert any(abs(value) > 0 for value in vector)

    def test_chat_model_returns_nonempty_text(
        self,
        live_ollama: Any,
        integration_unavailable: Callable[[str], NoReturn],
        monkeypatch: pytest.MonkeyPatch,
    ) -> None:
        from hawki_model_providers.ollama import OllamaProvider

        model = _configured_model("OLLAMA_RAG_MODEL", "llama3.1:8b")
        if not live_ollama.has_model(model):
            integration_unavailable(
                f"Ollama chat model '{model}' is not installed; run `ollama pull {model}`"
            )

        monkeypatch.setenv("OLLAMA_API_URL", live_ollama.api_url)
        monkeypatch.setenv("OLLAMA_RAG_MODEL", model)
        monkeypatch.setenv(
            "OLLAMA_CHAT_TIMEOUT",
            os.environ.get("RAWKI_INTEGRATION_MODEL_TIMEOUT", "120"),
        )
        monkeypatch.setenv("OLLAMA_CHAT_RETRIES", "0")
        monkeypatch.setenv("OLLAMA_NUM_PREDICT", "12")

        answer = OllamaProvider().chat(
            "Reply with one short word.",
            [{"role": "user", "content": "Is this live Ollama request working?"}],
            temperature=0.0,
        )

        assert isinstance(answer, str)
        assert answer.strip()
